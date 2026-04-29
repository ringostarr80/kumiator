<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\WebAuthn\PasskeyLoginContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;

/**
 * Schreibt Activity-Log-Einträge für Authentifizierungs-Ereignisse:
 * Anmeldung, Abmeldung, fehlgeschlagene Versuche und Lockouts.
 *
 * Symmetrie zum Passkey-Pfad: Vor Einführung dieses Listeners wurden nur
 * erfolgreiche Passkey-Anmeldungen geloggt (über
 * `PasskeyCredential::recordSuccessfulLoginActivity()`). Passwort-Logins,
 * Logouts und Fehlversuche blieben unsichtbar — das war forensisch und
 * audit-technisch eine Lücke (DSGVO Art. 32 verlangt angemessene
 * Sicherheitsmaßnahmen, dazu zählt die Nachvollziehbarkeit von
 * Authentifizierungsvorgängen).
 *
 * Doppel-Log-Vermeidung: Die Passkey-Anmeldung löst über `Auth::login()` im
 * `PasskeyAuthenticationController` ebenfalls ein `Login`-Event aus. Damit
 * dieser nicht zusätzlich als generischer Passwort-Login erscheint, setzt der
 * Controller vorher `PasskeyLoginContext::markActive()` — `handleLogin()`
 * überspringt das Logging in diesem Fall.
 *
 * Datenminimierung (DSGVO Art. 5 Abs. 1 lit. c): Bei `Failed`/`Lockout` ist
 * kein Causer bekannt, und die eingegebene E-Mail kann zu beliebigen Dritten
 * gehören (Tippfehler, Brute-Force gegen fremde Konten). Sie wird daher
 * **nicht im Klartext** abgelegt, sondern als SHA-256-Hash über die
 * normalisierte (`strtolower` + `trim`) Eingabe — das erlaubt Korrelation
 * gleicher Angreifer-Versuche, ohne personenbezogene Klartext-Daten zu
 * speichern.
 *
 * Registrierung: Event-Auto-Discovery wertet die Type-Hints der `handle*`-
 * Methoden aus — keine zusätzliche `Event::listen()`-Bindung nötig (siehe
 * Architektur-Hinweis in `LogRoleChangeListener`).
 */
final class LogAuthenticationActivityListener
{
    private const LOG_NAME = 'auth';

    public function handleLogin(Login $event): void
    {
        // Passkey-Logins werden bereits über
        // `PasskeyCredential::recordSuccessfulLoginActivity()` dokumentiert.
        if (PasskeyLoginContext::isActive()) {
            return;
        }

        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('password_login_succeeded')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'guard' => $event->guard,
                'remember' => (bool) $event->remember,
            ])
            ->log(__('app.activity_password_login_succeeded'));
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('logout')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['guard' => $event->guard])
            ->log(__('app.activity_logout'));
    }

    public function handleFailed(Failed $event): void
    {
        $properties = ['guard' => $event->guard];

        $email = $event->credentials['email'] ?? null;
        $emailHash = $this->hashEmail(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('login_failed')
            ->withProperties($properties)
            ->log(__('app.activity_login_failed'));
    }

    public function handleLockout(Lockout $event): void
    {
        $properties = [];

        $email = $event->request->input('email');
        $emailHash = $this->hashEmail(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('login_locked_out')
            ->withProperties($properties)
            ->log(__('app.activity_login_locked_out'));
    }

    private function hashEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($email));

        if ($normalised === '') {
            return null;
        }

        return hash('sha256', $normalised);
    }
}
