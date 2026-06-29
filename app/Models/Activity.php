<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * App-eigenes Activity-Log-Model von spatie/laravel-activitylog.
 *
 * **Kanonisches Model für Lesen UND Schreiben**
 * `config/activitylog.php → activity_model` zeigt auf diese Klasse, daher gehen
 * sowohl Lese-Zugriffe (z. B. `ActivityLogTable`) als
 * auch alle von Spatie erzeugten Schreibvorgänge (`LogsActivity`-Trait der
 * Domain-Models, `Activity`-Facade in den Listenern) hierüber. Höhere Schichten
 * dürfen laut Architektur-Regeln ohnehin nur auf `App\Models` zugreifen, nicht
 * auf Vendor-Namespaces wie `Spatie\Activitylog`.
 *
 * **`description` ist abgeleitet, nicht gespeichert**
 * Die `description`-Spalte wurde aus der Tabelle entfernt (siehe Migration
 * `drop_description_from_activity_log`). Der Klartext wird zur **Lesezeit** aus
 * dem stabilen `event`-Code in der Locale des Betrachters abgeleitet:
 *  - bekannter Code → `ActivityEvent::description()` (aktuelle Übersetzung;
 *    Korrekturen wirken rückwirkend auf Alt-Einträge),
 *  - unbekannter Code (zurückgezogener/umbenannter Enum-Case) → der rohe
 *    `event`-Code als ehrlicher, nie leerer Fallback.
 *
 * Der `set`-Teil des Accessors **verschluckt** Schreibversuche bewusst: Spaties
 * `LogActivityAction` setzt `description` bei jedem Schreibvorgang, und auch die
 * `Activity::saving`-Hooks könnten es tun. Ohne Swallow landete der Wert in der
 * nicht mehr existierenden Spalte und der INSERT bräche.
 */
final class Activity extends SpatieActivity
{
    /**
     * Read-time aus `event` abgeleitete Beschreibung; bewusst nicht persistiert.
     *
     * @return Attribute<string, never>
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn (): string => ActivityEvent::tryFrom((string) $this->event)?->description()
                ?? (string) ($this->event ?? ''),
            set: fn (): array => [],
        );
    }
}
