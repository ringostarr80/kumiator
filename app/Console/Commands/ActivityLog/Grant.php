<?php

declare(strict_types=1);

namespace App\Console\Commands\ActivityLog;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Gewährt einem einzelnen Benutzer das Recht, das Activity-Log einzusehen
 * (Direkt-Permission, nicht über eine Rolle — siehe `RoleSeeder`).
 *
 * Die Vergabe wird über den `LogPermissionChangeListener` automatisch als
 * `permission_attached` auditiert (Causer auf der CLI anonymisiert, Akteur im
 * `cli_actor`-Property). `givePermissionTo()` feuert das Attach-Event auch bei
 * einem No-Op; deshalb fängt der No-Op-Guard eine doppelte Vergabe vor dem
 * Aufruf ab, damit kein falsch-positiver Audit-Eintrag entsteht.
 */
#[Signature('activity-log:grant')]
#[Description('Gewährt einem Benutzer das Recht, das Activity-Log einzusehen')]
class Grant extends ActivityLogPermissionCommand
{
    protected function titleKey(): string
    {
        return 'commands.activity_log_grant.title';
    }

    protected function noOpMessageKey(): string
    {
        return 'commands.activity_log_grant.already_granted';
    }

    protected function successMessageKey(): string
    {
        return 'commands.activity_log_grant.success';
    }

    protected function isNoOp(User $user): bool
    {
        return $user->hasDirectPermission(PermissionName::ACTIVITY_LOG_VIEW->value);
    }

    protected function applyPermissionChange(User $user): void
    {
        $user->givePermissionTo(PermissionName::ACTIVITY_LOG_VIEW->value);
    }
}
