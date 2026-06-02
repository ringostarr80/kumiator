<?php

declare(strict_types=1);

namespace App\Console\Commands\ActivityLog;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gewährt einem einzelnen Benutzer das Recht, das Activity-Log einzusehen
 * (Direkt-Permission `activity-log.view`, nicht über eine Rolle — siehe
 * `RoleSeeder` und `App\Console\Commands\ActivityLog\Revoke`).
 *
 * Die Vergabe wird über den `LogPermissionChangeListener` automatisch als
 * `permission_attached` auditiert (Causer auf der CLI anonymisiert, Akteur im
 * `cli_actor`-Property). `givePermissionTo()` feuert das Attach-Event auch bei
 * einem No-Op; deshalb fängt der `hasDirectPermission()`-Guard eine doppelte
 * Vergabe vor dem Aufruf ab, damit kein falsch-positiver Audit-Eintrag entsteht.
 */
#[Signature('activity-log:grant')]
#[Description('Gewährt einem Benutzer das Recht, das Activity-Log einzusehen')]
class Grant extends Command
{
    public function handle(): int
    {
        $title = __('commands.activity_log_grant.title');
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

        if ($user->hasDirectPermission('activity-log.view')) {
            $this->warn(__('commands.activity_log_grant.already_granted', [
                'name' => $user->name,
                'email' => $email,
            ]));

            return self::SUCCESS;
        }

        $user->givePermissionTo('activity-log.view');

        $this->info(__('commands.activity_log_grant.success', [
            'name' => $user->name,
            'email' => $email,
        ]));

        return self::SUCCESS;
    }
}
