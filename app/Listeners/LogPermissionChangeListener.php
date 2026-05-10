<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Permission\PermissionSeederContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Models\Permission;

/**
 * Schreibt Activity-Log-Einträge bei Permission-Zuweisungen und -Entzügen
 * — symmetrisch zu `LogRoleChangeListener`.
 *
 * Spatie\Permission feuert keine Eloquent-Events auf dem Subject (User
 * oder Role), weil Permissions über eine Pivot-Tabelle verknüpft sind.
 * Ohne diesen Listener bliebe der berechtigungsrelevante Vorgang „Wer
 * hat wann welche Permission bekommen / verloren?" ungeloggt. Heute ist
 * das nur via `RoleSeeder` relevant; der Listener ist die Vorbereitung
 * für ein späteres Admin-UI.
 *
 * Registrierung: Läuft über Laravels Event-Auto-Discovery (Type-Hints
 * der `handle*`-Methoden). NICHT zusätzlich via `Event::listen()`
 * registrieren — sonst feuert der Listener doppelt.
 *
 * Seeder-Skip: `PermissionSeederContext` unterdrückt das Logging während
 * idempotenter Seeder-Läufe. Spatie feuert `PermissionAttachedEvent` auch
 * dann, wenn der interne `attach()` ein No-Op ist (Berechtigung war
 * bereits gesetzt) — ohne diesen Skip würde jeder Deploy einen unechten
 * Audit-Eintrag erzeugen.
 *
 * Bekannte Spatie-Quirks:
 *  - `syncPermissions()` macht intern `permissions()->detach()` **ohne
 *    Event** und ruft danach `givePermissionTo()` (das nur das
 *    Attach-Event feuert). Entzüge über `syncPermissions()` werden also
 *    aktuell nicht geloggt — das ist heute irrelevant (kein Call-Site),
 *    muss aber bei Einführung eines Admin-UIs durch einen
 *    `PermissionSyncService` mit explizitem Δ-Logging adressiert werden.
 *    Der Test `testSyncPermissionsOnlyLogsAttachAndDocumentsSpatieGap`
 *    schreibt das heutige Verhalten fest — wenn Spatie das in einer
 *    späteren Version repariert, bricht der Test bewusst.
 *  - Single-Guard-Annahme: Die in `properties.permissions` geloggten
 *    Namen tragen keine Guard-Information. Das ist heute eindeutig (nur
 *    `web`-Guard); bei Einführung weiterer Guards muss `guard_name`
 *    ergänzt werden — gleiche Bedingung wie in `LogRoleChangeListener`.
 */
final class LogPermissionChangeListener
{
    public function handleAttached(PermissionAttachedEvent $event): void
    {
        $this->log($event->model, $event->permissionsOrIds, 'permission_attached');
    }

    public function handleDetached(PermissionDetachedEvent $event): void
    {
        $this->log($event->model, $event->permissionsOrIds, 'permission_detached');
    }

    private function log(Model $subject, mixed $permissionsOrIds, string $event): void
    {
        if (PermissionSeederContext::isActive()) {
            return;
        }

        $permissionNames = $this->resolvePermissionNames($permissionsOrIds);

        if ($permissionNames === []) {
            return;
        }

        // `event` bleibt der stabile Maschinen-Code (für Filter/Reports),
        // `description` ist die übersetzte Klartext-Beschreibung für die UI
        // (Schema `app.activity_<event>`, analog zum Role-Listener).
        Activity::useLog('permission')
            ->performedOn($subject)
            ->withProperties(['permissions' => $permissionNames])
            ->event($event)
            ->log(__('app.activity_' . $event));
    }

    /**
     * Normalisiert den heterogenen `$permissionsOrIds`-Parameter zu einer
     * Liste von Permission-Namen. Spatie liefert je nach Codepfad Arrays
     * von IDs, einzelne `PermissionContract`-Instanzen oder Collections —
     * der Listener muss alle Varianten zusammenführen.
     *
     * @return list<string>
     */
    private function resolvePermissionNames(mixed $permissionsOrIds): array
    {
        if ($permissionsOrIds instanceof Collection) {
            $permissionsOrIds = $permissionsOrIds->all();
        }

        if (!is_array($permissionsOrIds)) {
            $permissionsOrIds = [$permissionsOrIds];
        }

        $ids = [];
        $names = [];

        foreach ($permissionsOrIds as $item) {
            if ($item instanceof PermissionContract) {
                $names[] = $item->name;

                continue;
            }

            if (is_int($item) || is_string($item)) {
                $ids[] = $item;
            }
        }

        if ($ids !== []) {
            $found = Permission::query()->whereIn('id', $ids)->get();

            // Drift sichtbar machen: ID, zu der keine Permission (mehr)
            // existiert, fiele sonst still aus dem Activity-Log. Das
            // `Log::warning` surface-t den Vorfall, ohne den
            // Schreibvorgang abzubrechen — analog zu LogRoleChangeListener.
            $missing = array_values(array_diff($ids, $found->modelKeys()));

            if ($missing !== []) {
                Log::warning('LogPermissionChangeListener: unknown permission IDs received', [
                    'missing_ids' => $missing,
                ]);
            }

            foreach ($found as $permission) {
                $names[] = $permission->name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
