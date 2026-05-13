<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Http\Livewire\LogoutOtherBrowserSessionsForm as JetstreamLogoutOtherBrowserSessionsForm;
use Spatie\Activitylog\Facades\Activity;

/**
 * Erweiterung der Jetstream-Komponente um Activity-Log-Erfassung.
 *
 * Jetstream feuert kein Framework-Event, wenn der Nutzer alle anderen
 * Browser-Sessions terminiert — dabei ist genau das ein
 * sicherheitsrelevanter Vorgang, der nachvollziehbar bleiben muss
 * (DSGVO Art. 32). Wir erweitern die Standard-Komponente und schreiben
 * den Eintrag, nachdem der Parent erfolgreich abgeschlossen hat.
 */
final class LogoutOtherBrowserSessionsForm extends JetstreamLogoutOtherBrowserSessionsForm
{
    private OtherDeviceLogoutContextContract $logoutContext;

    /**
     * Livewire's `boot()` ist Container-aware und löst Type-Hints per DI auf —
     * wir können den Parameter nicht in `logoutOtherBrowserSessions()` selbst
     * deklarieren, weil ein zusätzlicher Pflichtparameter dort die Parent-
     * Signatur brechen würde (LSP).
     */
    public function boot(OtherDeviceLogoutContextContract $logoutContext): void
    {
        $this->logoutContext = $logoutContext;
    }

    public function logoutOtherBrowserSessions(StatefulGuard $guard): void
    {
        // Wenn die Session-Persistenz nicht über die Datenbank läuft, terminiert
        // der Parent gar keine Sessions (frühzeitiger Return). Dann wäre ein
        // Activity-Log-Eintrag irreführend — wir loggen nur tatsächlich
        // ausgeführte Vorgänge.
        if (Config::string('session.driver') !== 'database') {
            parent::logoutOtherBrowserSessions($guard);

            return;
        }

        // Anzahl der gleich zu terminierenden Sessions vorab erfassen — nach
        // dem Parent-Aufruf wären sie aus der Tabelle entfernt.
        $terminatedSessionCount = $this->countOtherSessions();

        // Parent ruft `$guard->logoutOtherDevices()` auf — das feuert nativ
        // `OtherDeviceLogout`, das vom `LogAuthenticationActivityListener`
        // verarbeitet würde. Im Form-Pfad schreiben wir aber selbst einen
        // reicheren Eintrag mit `terminated_session_count`; der Marker drückt
        // den allgemeinen Listener-Eintrag während dieses Aufrufs weg.
        $this->logoutContext->markActive();

        try {
            parent::logoutOtherBrowserSessions($guard);
        } finally {
            $this->logoutContext->clear();
        }

        // Parent wirft `ValidationException` bei falschem Passwort — kommen
        // wir hier an, war der Logout erfolgreich.
        $user = Auth::user();

        if (!$user instanceof Model) {
            return;
        }

        Activity::useLog('auth')
            ->event('other_sessions_logged_out')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['terminated_session_count' => $terminatedSessionCount])
            ->log(__('app.activity_other_sessions_logged_out'));
    }

    private function countOtherSessions(): int
    {
        if (Config::string('session.driver') !== 'database') {
            return 0;
        }

        $userId = Auth::user()?->getAuthIdentifier();

        if ($userId === null) {
            return 0;
        }

        $connection = Config::get('session.connection');
        $connection = is_string($connection)
            ? $connection
            : null;

        return DB::connection($connection)
            ->table(Config::string('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->where('id', '!=', request()->session()->getId())
            ->count();
    }
}
