<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Services\Console\Contracts\ConsoleActorContextContract;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Process-globaler Marker für den aktuell laufenden Artisan-Command.
 *
 * Aktivierung: `CaptureConsoleActorListener` füllt den Marker bei jedem
 * `Illuminate\Console\Events\CommandStarting` und räumt ihn im zugehörigen
 * `CommandFinished` wieder ab — pro Artisan-Invocation also genau ein
 * Aktiv-Aus-Zyklus. Nested Commands kommen im Projekt aktuell nicht vor;
 * sollte ein Command einen anderen aufrufen, würde der innere `Finished`
 * den Outer-Kontext zu früh leeren — das ist bewusst akzeptiert, weil das
 * Pattern dann sowieso überdacht werden muss.
 *
 * Statisches Design ist hier vertretbar (analog `SelfRegistrationContext`):
 * PHP-CLI ist single-process, single-threaded, und der Lebenszyklus ist
 * exakt eine Command-Ausführung. In Tests ruft `Artisan::call(...)` denselben
 * Kernel auf, weshalb beide Events feuern und der Marker auch dort sauber
 * aktiviert/abgeräumt wird; eine zusätzliche `setUp()`-Säuberung ist daher
 * im Normalfall nicht nötig (defensive Tests können trotzdem
 * `clearStatically()` aufrufen).
 *
 * Effekt auf den Activity-Log: `applyToActivity()` wird vom zentralen
 * `Activity::saving`-Hook aufgerufen und macht zwei Dinge an jedem
 * während der Command-Ausführung entstehenden Eintrag:
 *   (a) hängt das `cli_actor`-Property an (OS-User/Hostname/Command) —
 *       der eigentliche, denormalisierte CLI-Marker im Audit-Log,
 *   (b) anonymisiert den Causer (`causer_id`/`causer_type` → null) —
 *       im CLI handelt ein Admin, kein User-Account; ein vom Listener
 *       (`LogTwoFactorActivityListener::causedBy($user)`) oder von
 *       Spatie's `CauserResolver` (über `Auth::user()`) gesetzter
 *       Causer wäre semantisch falsch und wird daher überschrieben.
 *       Wer im CLI bewusst einen User-Causer schreiben will, muss
 *       den Marker temporär deaktivieren.
 *
 * Das fachliche Event-Labeling ist bewusst NICHT Aufgabe dieses Hooks —
 * Domain-Models (z. B. `User`, `PasskeyCredential`) labeln ihre
 * Lifecycle-Events selbst auf channel-agnostische Codes; der CLI-Marker
 * steckt rein in (a) und die Anonymisierung in (b).
 */
final class ConsoleActorContext implements ConsoleActorContextContract
{
    /**
     * @var ?array<string, string>
     */
    private static ?array $actor = null;

    /**
     * @param array<string, string> $properties
     */
    public function activate(array $properties): void
    {
        self::$actor = $properties;
    }

    public function clear(): void
    {
        self::$actor = null;
    }

    public function isActive(): bool
    {
        return self::$actor !== null;
    }

    /**
     * Statische Variante für den `Activity::saving`-Listener im
     * `AppServiceProvider`, der pro Insert läuft und keinen Container-
     * Lookup rechtfertigt.
     *
     * Wendet zwei CLI-Effekte an, sobald der Kontext aktiv ist:
     *   (a) hängt das `cli_actor`-Property an,
     *   (b) nullt `causer_id`/`causer_type` — siehe Klassen-PHPDoc.
     */
    public static function applyToActivity(Activity $activity): void
    {
        $actor = self::$actor;

        if ($actor === null) {
            return;
        }

        // `properties` ist typseitig nullable; Spatie initialisiert die
        // Collection erst beim Setzen via `withProperties()`. Für unseren
        // Anhang reicht es, im Null-Fall mit einer leeren Collection zu
        // starten — der Rest läuft generisch über `put()`.
        $properties = $activity->properties ?? new Collection();
        $activity->properties = $properties->put('cli_actor', $actor);

        // Im CLI handelt ein Admin, kein User-Account. Jeder vom Listener
        // (z. B. `LogTwoFactorActivityListener::causedBy($user)`) oder von
        // Spatie's Default-`CauserResolver` (über `Auth::user()`) gesetzte
        // Causer wird hier überschrieben — die forensisch korrekte
        // Information „wer hat gehandelt" steckt im `cli_actor`-Property,
        // nicht im Causer-Feld.
        $activity->causer_id = null;
        $activity->causer_type = null;
    }

    /**
     * Test-Hilfe für defensives Teardown — analog `SelfRegistrationContext`.
     * Im Normalbetrieb cleart der `CommandFinished`-Listener.
     */
    public static function clearStatically(): void
    {
        self::$actor = null;
    }
}
