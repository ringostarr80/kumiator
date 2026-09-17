<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Livewire\Profile\Concerns\RequiresFreshPasswordConfirmation;
use App\Models\User;
use App\Services\Auth\Contracts\OtherSessionRevokerContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm as JetstreamLogoutOtherBrowserSessionsForm;
use Spatie\Activitylog\Facades\Activity;

/**
 * Ersetzt den Passwortvergleich der Jetstream-Komponente durch den zentralen
 * Bestätigungspfad und schreibt den Vorgang ins Activity-Log.
 *
 * Jetstream prüft das Passwort selbst gegen den Hash und beendet die Sitzungen
 * über `SessionGuard::logoutOtherDevices()`, das dafür das Klartextpasswort
 * verlangt. Beides verträgt sich nicht mit dem abschaltbaren Passwort-Login:
 * Die Abschaltung bliebe wirkungslos, und ein Konto ohne nutzbares Passwort käme
 * an die Funktion gar nicht mehr heran. Geerbt bleibt die Komponente wegen
 * ihrer Sitzungsliste, die die View über `$this->sessions` bezieht. Ihr
 * `$password` fährt dabei ohne Leser im Livewire-Snapshot mit — die Methode,
 * die es prüfte, ist hier überschrieben.
 *
 * Das Activity-Log ist der zweite Grund für die Erweiterung: Jetstream feuert
 * kein Framework-Event, obwohl der Vorgang sicherheitsrelevant und nach
 * DSGVO Art. 32 nachvollziehbar sein muss.
 */
final class LogoutOtherBrowserSessionsForm extends JetstreamLogoutOtherBrowserSessionsForm
{
    use RequiresFreshPasswordConfirmation;

    private OtherSessionRevokerContract $sessionRevoker;

    private UserSessionTerminatorContract $sessionTerminator;

    /**
     * Livewire's `boot()` ist Container-aware und löst Type-Hints per DI auf —
     * wir können die Parameter nicht in `logoutOtherBrowserSessions()` selbst
     * deklarieren, weil ein zusätzlicher Pflichtparameter dort die Parent-
     * Signatur brechen würde (LSP).
     */
    public function boot(
        OtherSessionRevokerContract $sessionRevoker,
        UserSessionTerminatorContract $sessionTerminator,
    ): void {
        $this->sessionRevoker = $sessionRevoker;
        $this->sessionTerminator = $sessionTerminator;
    }

    /**
     * Der Guard-Parameter stammt aus der Parent-Signatur und bleibt ungenutzt:
     * Sein `logoutOtherDevices()` zieht seine Wirkung aus einem neuen
     * Passwort-Hash, den es aus dem Klartextpasswort bildet.
     */
    public function logoutOtherBrowserSessions(StatefulGuard $guard): void
    {
        $this->ensurePasswordIsConfirmed(self::FRESH_CONFIRMATION_SECONDS);

        $user = Auth::user();

        if (!$user instanceof User) {
            return;
        }

        $revokedSessionCount = $this->sessionRevoker->revokeFor($user);

        $this->confirmingLogout = false;

        // Nur wenn die Sitzungen in der Datenbank liegen — sonst kam der Widerruf
        // an keine heran, und der Eintrag behauptete Sitzungsenden, die es nicht
        // gab. Die Entwertung des Recaller-Cookies läuft davor trotzdem; sie
        // braucht keinen Treiber.
        if ($this->sessionTerminator->usesDatabaseDriver()) {
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::OTHER_SESSIONS_LOGGED_OUT->value)
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties(['terminated_session_count' => $revokedSessionCount])
                ->log('');
        }

        $this->dispatch('loggedOut');
    }
}
