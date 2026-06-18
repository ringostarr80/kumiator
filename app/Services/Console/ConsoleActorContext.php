<?php

declare(strict_types=1);

namespace App\Services\Console;

use App\Services\Console\Contracts\ConsoleActorContextContract;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Prozess-globale Implementierung von `ConsoleActorContextContract`; das
 * Marker-Konzept und die Verdrahtung (DI-Listener) stehen im Interface.
 *
 * Lifecycle: bei `CommandStarting` gefüllt, bei `CommandFinished` geräumt —
 * pro Artisan-Invocation genau ein Aktiv-Aus-Zyklus. Langlebige Worker spannen
 * mit diesem Eventpaar die gesamte Prozesslaufzeit auf und werden daher vom
 * `CaptureConsoleActorListener` von der Aktivierung ausgenommen; sonst erbte
 * jeder in einem Job geschriebene Eintrag den CLI-Marker samt genulltem Causer.
 * Nested Commands kommen im Projekt aktuell nicht vor; riefe ein Command einen
 * anderen auf, würde der innere `Finished` den Outer-Kontext zu früh leeren —
 * bewusst akzeptiert, weil das Pattern dann sowieso überdacht werden muss.
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
 *       im CLI handelt ein Admin, kein User-Account; ein von einem Listener
 *       oder von Spatie's `CauserResolver` (über `Auth::user()`) gesetzter
 *       Causer wäre semantisch falsch und wird daher überschrieben.
 *       Wer im CLI bewusst einen User-Causer schreiben will, muss
 *       den Marker temporär deaktivieren.
 *
 * Das fachliche Event-Labeling ist bewusst NICHT Aufgabe dieses Hooks —
 * Domain-Models (z. B. `User`, `PasskeyCredential`) labeln ihre
 * Lifecycle-Events selbst auf channel-agnostische Codes.
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
     */
    public static function applyToActivity(Activity $activity): void
    {
        $actor = self::$actor;

        if ($actor === null) {
            return;
        }

        $properties = $activity->properties ?? new Collection();
        $activity->properties = $properties->put('cli_actor', $actor);

        // Causer anonymisieren: im CLI handelt ein Admin, kein User-Account
        // (Hintergrund + Escape-Hatch im Klassen-PHPDoc).
        $activity->causer_id = null;
        $activity->causer_type = null;
    }

    /**
     * Test-Hilfe für defensives Teardown. Im Normalbetrieb cleart der
     * `CommandFinished`-Listener.
     */
    public static function clearStatically(): void
    {
        self::$actor = null;
    }
}
