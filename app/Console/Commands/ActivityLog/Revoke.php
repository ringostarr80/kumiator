<?php

declare(strict_types=1);

namespace App\Console\Commands\ActivityLog;

use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Entzieht einem Benutzer das Recht, das Activity-Log einzusehen.
 *
 * Der Entzug wird über den `LogPermissionChangeListener` automatisch als
 * `permission_detached` auditiert. `revokePermissionTo()` feuert das
 * Detach-Event nur, wenn die Permission tatsächlich gebunden war; der
 * No-Op-Guard meldet einen Leerlauf vorab als Hinweis, statt einen
 * wirkungslosen Aufruf abzusetzen.
 */
#[Signature('activity-log:revoke')]
#[Description('Entzieht einem Benutzer das Recht, das Activity-Log einzusehen')]
class Revoke extends ActivityLogPermissionCommand
{
    protected function titleKey(): string
    {
        return 'commands.activity_log_revoke.title';
    }

    protected function noOpMessageKey(): string
    {
        return 'commands.activity_log_revoke.not_granted';
    }

    protected function successMessageKey(): string
    {
        return 'commands.activity_log_revoke.success';
    }

    protected function isNoOp(User $user): bool
    {
        return !$user->hasDirectPermission(PermissionName::ACTIVITY_LOG_VIEW->value);
    }

    protected function applyPermissionChange(User $user): void
    {
        $user->revokePermissionTo(PermissionName::ACTIVITY_LOG_VIEW->value);
    }
}
