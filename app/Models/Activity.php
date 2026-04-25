<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Lokaler Wrapper für das Activity-Log-Model von spatie/laravel-activitylog.
 *
 * **Warum die Klasse existiert**
 * Höhere Schichten (Livewire-Komponenten, Policies, …) dürfen laut den
 * Architektur-Regeln (`LivewireComponentsAreIndependentTest`,
 * `PoliciesDependOnlyOnModelsTest`) nur auf `App\Models` zugreifen, nicht
 * direkt auf Vendor-Namespaces wie `Spatie\Activitylog`. Dieser Wrapper ist
 * der minimale App-eigene Anker, der das Lesen aus dem Activity-Log möglich
 * macht, ohne die Architektur-Regeln aufzuweichen.
 *
 * **Asymmetrie zwischen Lesen und Schreiben**
 * Diese Klasse wird ausschließlich **für Lese-Zugriffe** genutzt (z. B.
 * `App\Livewire\Admin\ActivityLogTable`). Geschrieben werden Activity-Einträge
 * von Spatie selbst — sowohl über den `LogsActivity`-Trait der App-Models als
 * auch über die `Activity`-Facade im `LogRoleChangeListener`. Beide gehen über
 * `Spatie\Activitylog\Models\Activity`, **nicht** über diesen Wrapper, da
 * `config/activitylog.php → activity_model` nicht angepasst ist.
 *
 * Beide Klassen bilden dieselbe Tabelle mit identischem Schema ab; das
 * Lesen über den Wrapper liefert dieselben Daten wie ein direktes Lesen
 * über Spatie.
 *
 * **Wann die Klasse zur „echten" werden sollte**
 * Sobald der Wrapper eigene Domain-Logik trägt (Query-Scopes, Accessors,
 * Mutators, Custom-Casts, …), sollte `config/activitylog.php` veröffentlicht
 * und auf `App\Models\Activity::class` umgestellt werden. Erst dann ist die
 * Klasse symmetrisch (Read **und** Write gehen über dieselbe Klasse) und
 * die zusätzliche Indirection trägt funktionalen Mehrwert. Solange der
 * Wrapper leer bleibt, ist die heutige Asymmetrie der pragmatisch günstigere
 * Kompromiss.
 */
final class Activity extends SpatieActivity
{
}
