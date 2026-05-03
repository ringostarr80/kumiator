<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Audit\AuditEmailHasher;
use App\Services\Auth\UnapprovedLoginContext;
use App\Services\WebAuthn\PasskeyLoginContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
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

        // DSGVO-Schutz für Lösch-Pfade (Self-Delete via `DeleteUser`,
        // zukünftige Hard-Deletes): Jetstreams `DeleteUserForm` ruft nach
        // `$deleter->delete(Auth::user()->fresh())` noch `Auth::logout()`
        // auf. Im Logout-Event landet aber die ORIGINAL `Auth::user()`-
        // Instanz — nicht die hart gelöschte Fresh-Kopie. Deren `exists`-
        // Flag ist noch `true`, weshalb ein In-Memory-Check nicht greift.
        // Wir prüfen daher direkt gegen die DB (ohne Scopes, damit wir
        // genau zwischen „hart gelöscht — Zeile weg" und „soft-deleted —
        // Zeile noch da" unterscheiden). Würde hier ein Eintrag entstehen,
        // hätten wir das gerade durch den Activity-Log-Purge entfernte
        // personenbezogene Restmaterial sofort wieder reinkippt.
        if (!$user->newQueryWithoutScopes()->whereKey($user->getKey())->exists()) {
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
        // Wenn `FortifyServiceProvider::authenticateUsing()` den Login wegen
        // fehlender Freischaltung verworfen hat, ist bereits ein dedizierter
        // `login_unapproved`-Eintrag geschrieben worden. Fortify feuert
        // anschließend zusätzlich `Failed` — den blenden wir hier aus, damit
        // pro unapproved-Versuch nur ein einziger, fachlich präziser Eintrag
        // entsteht (sonst würden Reports doppelt zählen).
        if (UnapprovedLoginContext::isActive()) {
            return;
        }

        $properties = ['guard' => $event->guard];

        $email = $event->credentials['email'] ?? null;
        $emailHash = AuditEmailHasher::hash(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('login_failed')
            ->withProperties($properties)
            ->log(__('app.activity_login_failed'));
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        // Fortify-Event: `$user` ist per PHPDoc als `App\Models\User` getypt,
        // also immer ein Eloquent-Model — kein zusätzlicher Guard nötig.
        $user = $event->user;

        Activity::useLog(self::LOG_NAME)
            ->event('password_updated')
            ->causedBy($user)
            ->performedOn($user)
            ->log(__('app.activity_password_updated'));
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('password_reset')
            ->causedBy($user)
            ->performedOn($user)
            ->log(__('app.activity_password_reset'));
    }

    public function handleLockout(Lockout $event): void
    {
        $properties = [];

        $email = $event->request->input('email');
        $emailHash = AuditEmailHasher::hash(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        Activity::useLog(self::LOG_NAME)
            ->event('login_locked_out')
            ->withProperties($properties)
            ->log(__('app.activity_login_locked_out'));
    }
}
