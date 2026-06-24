<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use App\Services\Concerns\MarksRequestScope;

/**
 * Request-scoped Marker, der signalisiert, dass das gerade laufende
 * `Auth::logoutOtherDevices()` aus dem `LogoutOtherBrowserSessionsForm`
 * stammt.
 *
 * Hintergrund: `SessionGuard::logoutOtherDevices()` feuert nativ
 * `Illuminate\Auth\Events\OtherDeviceLogout`. Damit auch direkte
 * Framework-Aufrufe (eigene Controller, künftige API, Tinker) im Audit-Log
 * landen, hängt `LogAuthenticationActivityListener::handleOtherDeviceLogout()`
 * an diesem Event. Das Livewire-Form schreibt aber bereits einen reicheren
 * `other_sessions_logged_out`-Eintrag mit `terminated_session_count` — ohne
 * Marker würde derselbe Vorgang doppelt erscheinen. Der Marker drückt den
 * Listener-Eintrag im Form-Pfad weg, der native Pfad bleibt unbeeinflusst.
 */
final class OtherDeviceLogoutContext implements OtherDeviceLogoutContextContract
{
    use MarksRequestScope;
}
