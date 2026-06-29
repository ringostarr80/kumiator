<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Services\Audit\AuditEmailHasher;
use App\Services\Audit\AuditIpTruncator;
use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\WebAuthn\PasskeyLoginContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Spatie\Activitylog\Facades\Activity;

/**
 * Schreibt Activity-Log-Einträge für Authentifizierungs-Ereignisse (Anmeldung,
 * Abmeldung, Fehlversuche, Lockouts) — symmetrisch zum Passkey-Pfad und weil
 * DSGVO Art. 32 die Nachvollziehbarkeit von Authentifizierungsvorgängen verlangt.
 *
 * Datenminimierung (DSGVO Art. 5 Abs. 1 lit. c): Bei `Failed`/`Lockout` ist
 * kein Causer bekannt, und die eingegebene E-Mail kann zu beliebigen Dritten
 * gehören (Tippfehler, Brute-Force gegen fremde Konten). Sie wird daher
 * **nicht im Klartext** abgelegt, sondern nur als Hash (Verfahren und
 * Begründung in `AuditEmailHasher`) — das erlaubt Korrelation gleicher
 * Angreifer-Versuche, ohne personenbezogene Klartext-Daten zu speichern.
 *
 * Kanal-Wahl (DSGVO Art. 5 Abs. 1 lit. e — Speicherbegrenzung): Die anonymen
 * Dritt-Forensik-Einträge `login_failed`, `login_locked_out` und
 * `password_reset_requested` schreiben in den {@see ActivityChannel::FORENSIC}-
 * Kanal statt nach `auth`, weil der `log_name` die Retention-Grenze ist — nur
 * `forensic` wird mit verkürzter Frist gelöscht. Die übrigen, einem Mitglied
 * zuordenbaren auth-Events (Login, Logout, 2FA, Passwort) bleiben im `auth`-
 * Kanal mit voller Frist.
 *
 * Registrierung: Event-Auto-Discovery wertet die Type-Hints der `handle*`-
 * Methoden aus — keine zusätzliche `Event::listen()`-Bindung nötig.
 */
final class LogAuthenticationActivityListener
{
    public function __construct(private readonly UnapprovedLoginContextContract $unapprovedLoginContext)
    {
    }

    public function handleLogin(Login $event): void
    {
        // Die Passkey-Anmeldung löst über `Auth::login()` ebenfalls ein `Login`-
        // Event aus; der Passkey-Pfad setzt davor den `PasskeyLoginContext`-Marker.
        // Hier überspringen, damit der Passkey-Login nicht zusätzlich als
        // generischer Passwort-Login erscheint (Doppel-Eintrag).
        if (app(PasskeyLoginContext::class)->isActive()) {
            return;
        }

        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_LOGIN_SUCCEEDED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'guard' => $event->guard,
                'remember' => (bool) $event->remember,
            ])
            ->log(ActivityEvent::PASSWORD_LOGIN_SUCCEEDED->description());
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        // DSGVO-Schutz für den Self-Delete-Hard-Delete (`DeleteUser` →
        // `UserHardDeleter`): Jetstreams `DeleteUserForm` ruft nach
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

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::LOGOUT->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['guard' => $event->guard])
            ->log(ActivityEvent::LOGOUT->description());
    }

    public function handleFailed(Failed $event): void
    {
        // Wenn `FortifyServiceProvider::authenticateUsing()` den Login wegen
        // fehlender Freischaltung verworfen hat, ist bereits ein dedizierter
        // `login_unapproved`-Eintrag geschrieben worden. Fortify feuert
        // anschließend zusätzlich `Failed` — den blenden wir hier aus, damit
        // pro unapproved-Versuch nur ein einziger, fachlich präziser Eintrag
        // entsteht (sonst würden Reports doppelt zählen).
        if ($this->unapprovedLoginContext->isActive()) {
            // Consume-once: den Marker direkt nach dem Unterdrücken räumen.
            // Setz- (Fortify-Closure) und Lesestelle liegen in getrennten
            // Frames, es gibt also kein umschließendes `finally` — bliebe der
            // Marker stehen, verschluckte er in einem wiederverwendeten
            // Container den nächsten echten `login_failed`-Eintrag.
            $this->unapprovedLoginContext->clear();

            return;
        }

        $properties = ['guard' => $event->guard];

        $email = $event->credentials['email'] ?? null;
        $emailHash = AuditEmailHasher::hash(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        // `Failed` trägt keinen Request — IP/UA daher aus dem aktuellen HTTP-Request.
        $properties += $this->forensicProperties(request());

        Activity::useLog(ActivityChannel::FORENSIC->value)
            ->event(ActivityEvent::LOGIN_FAILED->value)
            ->withProperties($properties)
            ->log(ActivityEvent::LOGIN_FAILED->description());
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $user = $event->user;

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_UPDATED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::PASSWORD_UPDATED->description());
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_RESET->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::PASSWORD_RESET->description());
    }

    /**
     * Anforderung eines Passwort-Reset-Links als Audit-Eintrag — das forensische
     * Gegenstück zum abschließenden `password_reset`. `PasswordResetLinkSent`
     * feuert erst, NACHDEM der Broker den User aufgelöst hat (nie für unbekannte
     * Adressen), deshalb identifiziert `performedOn($user)` den Betroffenen, ohne
     * eine fremde E-Mail aus Tippfehlern/Enumeration im Klartext abzulegen.
     *
     * KEIN `causedBy`: die Anforderung läuft als Gast auf `/forgot-password` —
     * der Anforderer ist nicht authentifiziert. Stattdessen anonyme Forensik
     * (gekürzte IP + User-Agent), wie in `handleFailed`.
     */
    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::FORENSIC->value)
            ->event(ActivityEvent::PASSWORD_RESET_REQUESTED->value)
            ->performedOn($user)
            ->withProperties($this->forensicProperties(request()))
            ->log(ActivityEvent::PASSWORD_RESET_REQUESTED->description());
    }

    /**
     * Bestätigung der E-Mail-Adresse als fachlicher Audit-Eintrag im `auth`-Log
     * — anstatt nur als generischer `user.updated` über `email_verified_at`.
     * Das Field wurde deshalb bewusst aus `User::getActivitylogOptions()`
     * entfernt; sonst entstünde derselbe Vorgang doppelt.
     */
    public function handleVerified(Verified $event): void
    {
        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_VERIFIED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::EMAIL_VERIFIED->description());
    }

    /**
     * Native `Auth::logoutOtherDevices()`-Aufrufe sichtbar machen. Der
     * Livewire-Form-Pfad schreibt einen reicheren `other_sessions_logged_out`-
     * Eintrag mit `terminated_session_count` und setzt davor den
     * `OtherDeviceLogoutContext`-Marker — den prüfen wir hier, um den
     * Form-Pfad nicht doppelt zu loggen. Der eigene Event-Code
     * `other_devices_logged_out` bleibt für native (Nicht-Form-)Aufrufe
     * reserviert.
     */
    public function handleOtherDeviceLogout(OtherDeviceLogout $event): void
    {
        if (app(OtherDeviceLogoutContextContract::class)->isActive()) {
            return;
        }

        $user = $event->user;

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::OTHER_DEVICES_LOGGED_OUT->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['guard' => $event->guard])
            ->log(ActivityEvent::OTHER_DEVICES_LOGGED_OUT->description());
    }

    public function handleLockout(Lockout $event): void
    {
        $properties = [];

        $email = $event->request->input('email');
        $emailHash = AuditEmailHasher::hash(is_string($email) ? $email : null);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        $properties += $this->forensicProperties($event->request);

        Activity::useLog(ActivityChannel::FORENSIC->value)
            ->event(ActivityEvent::LOGIN_LOCKED_OUT->value)
            ->withProperties($properties)
            ->log(ActivityEvent::LOGIN_LOCKED_OUT->description());
    }

    /**
     * Forensische Properties für anonyme Fehlversuche: gekürzte IP (Netz statt
     * Host — DSGVO-Datenminimierung) und der auf 255 Zeichen begrenzte
     * User-Agent, jeweils nur wenn vorhanden. Der User-Agent ist auf den
     * anonymen Pfaden angreiferkontrolliert; der Längen-Cap verhindert, dass
     * beliebig lange Header die langlebig aufbewahrte Forensik-Tabelle aufblähen.
     * Ohne Request-IP (z. B. CLI-Auth) bleibt das Array leer.
     *
     * @return array<string, string>
     */
    private function forensicProperties(Request $request): array
    {
        $properties = [];

        $ip = AuditIpTruncator::truncate($request->ip());

        if ($ip !== null) {
            $properties['ip'] = $ip;
        }

        $userAgent = $request->userAgent();

        if ($userAgent !== null) {
            $properties['user_agent'] = Str::limit($userAgent, 255, '');
        }

        return $properties;
    }
}
