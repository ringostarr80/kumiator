<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;
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
final class LogPermissionChangeListener extends LogAuthorizationChangeListener
{
    public function handleAttached(PermissionAttachedEvent $event): void
    {
        $this->log($event->model, $event->permissionsOrIds, ActivityEvent::PERMISSION_ATTACHED);
    }

    public function handleDetached(PermissionDetachedEvent $event): void
    {
        $this->log($event->model, $event->permissionsOrIds, ActivityEvent::PERMISSION_DETACHED);
    }

    private function log(Model $subject, mixed $permissionsOrIds, ActivityEvent $event): void
    {
        $permissionNames = $this->resolveModelNames(
            $permissionsOrIds,
            Permission::class,
            'LogPermissionChangeListener: unknown permission IDs received',
        );

        if ($permissionNames === []) {
            return;
        }

        // `event->value` ist der stabile Maschinen-Code (für Filter/Reports),
        // `description()` die übersetzte Klartext-Beschreibung für die UI
        // (analog zum Role-Listener).
        Activity::useLog(ActivityChannel::PERMISSION->value)
            ->performedOn($subject)
            ->withProperties(['permissions' => $permissionNames])
            ->event($event->value)
            ->log($event->description());
    }
}
