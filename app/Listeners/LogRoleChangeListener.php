<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
 *
 * Registrierung: Läuft über Laravels Event-Auto-Discovery (standardmäßig via
 * `Application::configure()->withEvents()`, ausgewertet über die Event-Typ-Hints
 * der `handle*`-Methoden). Der Listener darf deshalb **nicht** zusätzlich via
 * `Event::listen()` registriert werden — sonst feuert er doppelt und jede
 * Rollenänderung landet zweimal im Activity-Log. Der Regressionstest
 * `RoleChangeActivityLogTest::testAssigningMultipleRolesAtOnceLogsSingleEntryWithAllRoleNames`
 * deckt genau das ab.
 *
 * Single-Guard-Annahme: Die in `properties.roles` geloggten Namen tragen keine
 * Guard-Information mit. Das ist heute eindeutig, weil das Projekt nur den
 * `web`-Guard verwendet (siehe `config/auth.php`). In einem Multi-Guard-Setup
 * (z. B. zusätzlicher `api`-Guard via Sanctum-Personal-Access-Tokens mit
 * eigenen Spatie-Rollen) kann derselbe Rollen-Name in mehreren Guards
 * existieren — der Log-Eintrag wäre dann forensisch nicht mehr eindeutig.
 * Vor Einführung eines weiteren Guards muss dieser Listener so erweitert
 * werden, dass `guard_name` mit ins Property-Set wandert.
 */
final class LogRoleChangeListener
{
    public function handleAttached(RoleAttachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, ActivityEvent::ROLE_ATTACHED);
    }

    public function handleDetached(RoleDetachedEvent $event): void
    {
        $this->log($event->model, $event->rolesOrIds, ActivityEvent::ROLE_DETACHED);
    }

    private function log(Model $subject, mixed $rolesOrIds, ActivityEvent $event): void
    {
        $roleNames = $this->resolveRoleNames($rolesOrIds);

        if ($roleNames === []) {
            return;
        }

        // `event->value` ist der stabile Maschinen-Code (für Filter/Reports),
        // `description()` die übersetzte Klartext-Beschreibung für die UI.
        Activity::useLog(ActivityChannel::ROLE->value)
            ->performedOn($subject)
            ->withProperties(['roles' => $roleNames])
            ->event($event->value)
            ->log($event->description());
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
            $found = Role::query()->whereIn('id', $ids)->get();

            // Drift sichtbar machen: Spatie liefert die `$rolesOrIds`-Liste
            // unbearbeitet weiter — wenn dort eine ID steht, die in der
            // `roles`-Tabelle nicht (mehr) existiert, würde der Name sonst
            // still aus dem Activity-Log fallen. Ein Log::warning surface-t
            // das, ohne den Activity-Log-Schreibvorgang abzubrechen.
            $missing = array_values(array_diff($ids, $found->modelKeys()));

            if ($missing !== []) {
                Log::warning('LogRoleChangeListener: unknown role IDs received', [
                    'missing_ids' => $missing,
                ]);
            }

            foreach ($found as $role) {
                $names[] = $role->name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
