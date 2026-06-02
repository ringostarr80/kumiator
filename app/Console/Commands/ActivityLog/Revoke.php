<?php

declare(strict_types=1);

namespace App\Console\Commands\ActivityLog;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Entzieht einem Benutzer das Recht, das Activity-Log einzusehen (Gegenstück zu
 * `App\Console\Commands\ActivityLog\Grant`).
 *
 * Der Entzug wird über den `LogPermissionChangeListener` automatisch als
 * `permission_detached` auditiert. `revokePermissionTo()` feuert das
 * Detach-Event nur, wenn die Permission tatsächlich gebunden war; der
 * `hasDirectPermission()`-Guard meldet einen Leerlauf vorab als Hinweis, statt
 * einen wirkungslosen Aufruf abzusetzen.
 */
#[Signature('activity-log:revoke')]
#[Description('Entzieht einem Benutzer das Recht, das Activity-Log einzusehen')]
class Revoke extends Command
{
    public function handle(): int
    {
        $title = __('commands.activity_log_revoke.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        /** @var ?User $user */
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if (!$user->hasDirectPermission('activity-log.view')) {
            $this->warn(__('commands.activity_log_revoke.not_granted', [
                'name' => $user->name,
                'email' => $email,
            ]));

            return self::SUCCESS;
        }

        $user->revokePermissionTo('activity-log.view');

        $this->info(__('commands.activity_log_revoke.success', [
            'name' => $user->name,
            'email' => $email,
        ]));

        return self::SUCCESS;
    }
}
