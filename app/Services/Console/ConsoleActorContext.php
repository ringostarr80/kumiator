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
 * `Activity::saving`-Hook aufgerufen und (a) hängt das `cli_actor`-Property
 * an, (b) labelt für eine kuratierte Liste von User-Lifecycle-Commands den
 * generischen Eloquent-Event-Code auf einen fachlichen Code um — symmetrisch
 * zum bestehenden Self-Registration-Remap, aber für den CLI-Pfad.
 */
final class ConsoleActorContext implements ConsoleActorContextContract
{
    /**
     * Mapping `command-signature` → ('log_name', 'event' aus Eloquent-Trait,
     * fachlicher Ziel-Code). Der Hook fasst NUR Einträge an, die exakt zu
     * dem aktuell laufenden Command gehören — andere Activity-Schreibvorgänge
     * (z. B. Listener im selben Request) bleiben unverändert.
     *
     * Bewusst kuratiert (keine Wildcards): jeder Eintrag hier ist ein
     * Audit-relevanter Vorgang mit eigener Übersetzung; reine Hilfs-Commands
     * sollen nicht versehentlich umgelabelt werden.
     */
    private const array CLI_EVENT_REMAP = [
        'user:create' => ['log_name' => 'user', 'event' => 'created', 'new_event' => 'user_created_via_cli'],
        'user:approve' => ['log_name' => 'user', 'event' => 'updated', 'new_event' => 'user_approved_via_cli'],
        'user:delete' => ['log_name' => 'user', 'event' => 'deleted', 'new_event' => 'user_deleted_via_cli'],
        'user:restore' => ['log_name' => 'user', 'event' => 'restored', 'new_event' => 'user_restored_via_cli'],
    ];

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

        // `properties` ist typseitig nullable; Spatie initialisiert die
        // Collection erst beim Setzen via `withProperties()`. Für unseren
        // Anhang reicht es, im Null-Fall mit einer leeren Collection zu
        // starten — der Rest läuft generisch über `put()`.
        $properties = $activity->properties ?? new Collection();
        $activity->properties = $properties->put('cli_actor', $actor);

        $command = $actor['command'] ?? null;

        if ($command === null) {
            return;
        }

        $remap = self::CLI_EVENT_REMAP[$command] ?? null;

        if ($remap === null) {
            return;
        }

        if ($activity->log_name !== $remap['log_name']) {
            return;
        }

        if ($activity->event !== $remap['event']) {
            return;
        }

        $activity->event = $remap['new_event'];
        $activity->description = __('app.activity_' . $remap['new_event']);
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
