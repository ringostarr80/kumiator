<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;

/**
 * Gemeinsame Basis der Listener, die Spatie-Zuweisungen (Rollen,
 * Permissions) ins Activity-Log spiegeln.
 *
 * Der Schreibvorgang ist für beide bis auf vier Konstanten (Channel,
 * Property-Key, Model-Klasse, Warntext) identisch und lebt darum hier; die
 * Subklassen liefern diese vier über abstrakte Akzessoren. So wird etwa ein
 * künftiges `guard_name`-Property nur an einer Stelle ergänzt.
 *
 * Spatie reicht die geänderten Subjekte je nach Codepfad als Array roher
 * IDs, als einzelne Contract-Instanz oder als Collection durch; die
 * Normalisierung auf eine sortierte Liste eindeutiger Namen lebt darum
 * ebenfalls hier.
 */
abstract class LogAuthorizationChangeListener
{
    abstract protected function activityChannel(): ActivityChannel;

    abstract protected function propertyKey(): string;

    /**
     * @return class-string<\Spatie\Permission\Models\Role|\Spatie\Permission\Models\Permission>
     */
    abstract protected function modelClass(): string;

    abstract protected function unknownIdsWarning(): string;

    protected function log(Model $subject, mixed $modelsOrIds, ActivityEvent $event): void
    {
        $names = $this->resolveModelNames($modelsOrIds, $this->modelClass(), $this->unknownIdsWarning());

        if ($names === []) {
            return;
        }

        // `event->value` ist der stabile Maschinen-Code (für Filter/Reports),
        // `description()` die übersetzte Klartext-Beschreibung für die UI.
        Activity::useLog($this->activityChannel()->value)
            ->performedOn($subject)
            ->withProperties([$this->propertyKey() => $names])
            ->event($event->value)
            ->log($event->description());
    }

    /**
     * @template TModel of \Spatie\Permission\Models\Role|\Spatie\Permission\Models\Permission
     * @param class-string<TModel> $modelClass Model zum Nachschlagen roher IDs
     * @param string $unknownIdsWarning Log-Nachricht, falls eine ID kein (mehr existierendes) Model trifft
     * @return list<string>
     */
    protected function resolveModelNames(mixed $modelsOrIds, string $modelClass, string $unknownIdsWarning): array
    {
        if ($modelsOrIds instanceof Collection) {
            $modelsOrIds = $modelsOrIds->all();
        }

        if (!is_array($modelsOrIds)) {
            $modelsOrIds = [$modelsOrIds];
        }

        $ids = [];
        $names = [];

        foreach ($modelsOrIds as $item) {
            if ($item instanceof RoleContract || $item instanceof PermissionContract) {
                $names[] = $item->name;

                continue;
            }

            if (is_int($item) || is_string($item)) {
                $ids[] = $item;
            }
        }

        if ($ids !== []) {
            $found = $modelClass::query()->whereIn('id', $ids)->get();

            // Drift sichtbar machen: eine ID, zu der kein Model (mehr)
            // existiert, fiele sonst still aus dem Activity-Log. Das
            // `Log::warning` surface-t den Vorfall, ohne den Schreibvorgang
            // abzubrechen.
            $missing = array_values(array_diff($ids, $found->modelKeys()));

            if ($missing !== []) {
                Log::warning($unknownIdsWarning, ['missing_ids' => $missing]);
            }

            foreach ($found as $model) {
                $names[] = $model->name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
