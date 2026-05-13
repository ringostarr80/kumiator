<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;

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
 *
 * Hybrid-API: Instanzmethoden auf einem Contract erfüllen die
 * Livewire-Architektur-Regel; der Listener und Tests greifen statisch
 * darauf zu (zwei Pfade, ein State — analog zu `SelfRegistrationContext`).
 *
 * Statisches Design ist hier vertretbar, weil PHP-Requests einen frischen
 * Prozess-Zustand haben (kein Carry-over zwischen Requests). In Tests muss
 * `clear()` zwischen Szenarien aufgerufen werden — der TestCase tut das
 * automatisch im `setUp()`.
 */
final class OtherDeviceLogoutContext implements OtherDeviceLogoutContextContract
{
    private static bool $active = false;

    public function markActive(): void
    {
        self::$active = true;
    }

    public function clear(): void
    {
        self::$active = false;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Statische Variante für Test-Teardown / TestCase-`setUp()`. Die
     * Instanz-Methode würde via Container aufgelöst werden müssen, was
     * beim Marker-Reset unnötig Indirektion einführt.
     */
    public static function clearStatically(): void
    {
        self::$active = false;
    }
}
