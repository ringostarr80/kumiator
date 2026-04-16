<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

/**
 * Schreibt Activity-Log-Einträge bei Rollen-Zuweisungen und -Entzügen.
 *
 * Spatie\Permission feuert keine Eloquent-Events auf dem User, weil Rollen
 * über eine Pivot-Tabelle verknüpft sind. Ohne diesen Listener bliebe der
 * fachlich wichtige Vorgang „Wer hat wann welche Rolle bekommen / verloren?"
 * ungeloggt.
 */
final class LogRoleChangeListener
{
    public function handleAttached(RoleAttachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, 'role_attached');
    }

    public function handleDetached(RoleDetachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, 'role_detached');
    }

    private function log(Model $subject, mixed $rolesOrIds, string $event): void
    {
        $roleNames = $this->resolveRoleNames($rolesOrIds);

        if ($roleNames === []) {
            return;
        }

        Activity::useLog('role')
            ->performedOn($subject)
            ->withProperties(['roles' => $roleNames])
            ->event($event)
            ->log($event);
    }

    /**
     * Normalisiert den heterogenen `$rolesOrIds`-Parameter zu einer Liste von Rollen-Namen.
     *
     * @return list<string>
     */
    private function resolveRoleNames(mixed $rolesOrIds): array
    {
        if ($rolesOrIds instanceof Collection) {
            $rolesOrIds = $rolesOrIds->all();
        }

        if (!is_array($rolesOrIds)) {
            $rolesOrIds = [$rolesOrIds];
        }

        $ids = [];
        $names = [];

        foreach ($rolesOrIds as $item) {
            if ($item instanceof RoleContract) {
                $names[] = $item->name;

                continue;
            }

            if (is_int($item) || is_string($item)) {
                $ids[] = $item;
            }
        }

        if ($ids !== []) {
            foreach (Role::query()->whereIn('id', $ids)->get() as $role) {
                $names[] = $role->name;
            }
        }

        return array_values(array_unique($names));
    }
}
