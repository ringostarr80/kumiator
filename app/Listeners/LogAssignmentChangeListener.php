<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;

/**
 * Gemeinsame Basis der Listener, die Spatie-Zuweisungen (Rollen,
 * Permissions) ins Activity-Log spiegeln.
 *
 * Spatie reicht die geänderten Subjekte je nach Codepfad als Array roher
 * IDs, als einzelne Contract-Instanz oder als Collection durch. Die
 * Normalisierung dieses heterogenen Parameters auf eine sortierte Liste
 * eindeutiger Namen ist für Rollen und Permissions Zeile für Zeile
 * identisch und lebt darum hier statt doppelt in jedem Listener.
 */
abstract class LogAssignmentChangeListener
{
    /**
     * @template TModel of \Spatie\Permission\Models\Role|\Spatie\Permission\Models\Permission
     * @param class-string<TModel> $modelClass Model zum Nachschlagen roher IDs
     * @param string $unknownIdsWarning Log-Nachricht, falls eine ID kein (mehr existierendes) Model trifft
     * @return list<string>
     */
    protected function resolveAssignmentNames(mixed $modelsOrIds, string $modelClass, string $unknownIdsWarning): array
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
