<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Contracts\SelfRegistrationContextContract;
use App\Services\Concerns\MarksRequestScope;

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
 */
final class SelfRegistrationContext implements SelfRegistrationContextContract
{
    use MarksRequestScope;
}
