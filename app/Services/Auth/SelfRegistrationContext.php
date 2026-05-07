<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Contracts\SelfRegistrationContextContract;

/**
 * Request-scoped Marker, der signalisiert, dass das gerade laufende
 * `User::create()` aus Fortifys Self-Registration-Pfad stammt
 * (`RegisteredUserController` → `CreateNewUser`).
 *
 * Hintergrund: `User` nutzt den `LogsActivity`-Trait und schreibt für jede
 * Anlage automatisch einen `user.created`-Eintrag. Web-Self-Registration und
 * Admin-CLI-Anlage (`user:create`) laufen heute über denselben Codepfad und
 * sind im Audit-Log nicht voneinander unterscheidbar — fachlich aber zwei
 * grundverschiedene Vorgänge (Public-Endpoint vs. interner Admin-Akt).
 *
 * Mechanik: `CreateNewUser` setzt den Marker direkt vor `User::create()`
 * und räumt ihn im `finally` wieder ab. Der `Activity::saving`-Listener im
 * `AppServiceProvider` prüft den Marker und labelt den `created`-Event auf
 * `user_self_registered` um (inkl. übersetzter Description). Ohne Marker
 * (CLI-Pfad, Tests, andere User-Anlagen) bleibt der Eintrag generisch.
 *
 * Statisches Design ist hier vertretbar, weil PHP-Requests einen frischen
 * Prozess-Zustand haben (kein Carry-over zwischen Requests). In Tests muss
 * `clear()` zwischen Szenarien aufgerufen werden — das bestehende Setup
 * im `AuthenticationActivityLogTest` tut das automatisch im `setUp()`.
 */
final class SelfRegistrationContext implements SelfRegistrationContextContract
{
    private static bool $active = false;

    public function markActive(): void
    {
        self::$active = true;
    }

    public function isActive(): bool
    {
        return self::$active;
    }

    public function clear(): void
    {
        self::$active = false;
    }

    /**
     * Statische Variante für den `Activity::saving`-Listener im
     * `AppServiceProvider`: dieser läuft im globalen Bootstrapping-Pfad,
     * eine DI-Auflösung pro Activity-Insert wäre unnötig teuer und brächte
     * keinen Nutzen — der Marker-Zustand ist Prozess-global.
     */
    public static function isActiveStatically(): bool
    {
        return self::$active;
    }

    /**
     * Statische Variante für Test-Teardown / TestCase-`setUp()`. Die
     * Instanz-Methoden würden via Container aufgelöst werden müssen,
     * was beim Marker-Reset unnötig Indirektion einführt.
     */
    public static function clearStatically(): void
    {
        self::$active = false;
    }
}
