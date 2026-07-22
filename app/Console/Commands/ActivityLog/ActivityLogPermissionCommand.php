<?php

declare(strict_types=1);

namespace App\Console\Commands\ActivityLog;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gemeinsamer Ablauf der `activity-log:grant`/`:revoke`-Commands: Titel
 * ausgeben, E-Mail erfragen, Benutzer laden (unbekannt → Fehler), den
 * No-Op-Guard prüfen und die Permission-Änderung ausführen. Richtung,
 * Guard-Polarität und Meldungs-Keys liefern die Subklassen über die
 * abstrakten Hooks.
 */
abstract class ActivityLogPermissionCommand extends Command
{
    abstract protected function titleKey(): string;

    abstract protected function noOpMessageKey(): string;

    abstract protected function successMessageKey(): string;

    abstract protected function isNoOp(User $user): bool;

    abstract protected function applyPermissionChange(User $user): void;

    public function handle(): int
    {
        $title = __($this->titleKey());
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if ($this->isNoOp($user)) {
            $this->warn(__($this->noOpMessageKey(), [
                'name' => $user->name,
                'email' => $email,
            ]));

            return self::SUCCESS;
        }

        $this->applyPermissionChange($user);

        $this->info(__($this->successMessageKey(), [
            'name' => $user->name,
            'email' => $email,
        ]));

        return self::SUCCESS;
    }
}
