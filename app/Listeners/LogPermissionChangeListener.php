<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
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
 * Single-Guard-Annahme: Die in `properties.permissions` geloggten Namen
 * tragen keine Guard-Information. Das ist heute eindeutig (nur `web`-Guard);
 * bei Einführung weiterer Guards muss `guard_name` ergänzt werden — gleiche
 * Bedingung wie in `LogRoleChangeListener`.
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

    protected function activityChannel(): ActivityChannel
    {
        return ActivityChannel::PERMISSION;
    }

    protected function propertyKey(): string
    {
        return 'permissions';
    }

    /**
     * @return class-string<Permission>
     */
    protected function modelClass(): string
    {
        return Permission::class;
    }

    protected function unknownIdsWarning(): string
    {
        return 'LogPermissionChangeListener: unknown permission IDs received';
    }
}
