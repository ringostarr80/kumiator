<?php

declare(strict_types=1);

namespace App\Console\Commands\Role;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

#[Signature('role:assign')]
#[Description('Weist einem Benutzer eine Rolle zu')]
class Assign extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.assign_role.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        /** @var ?User $user */
        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = Role::orderBy('name')->pluck('name')->toArray();

        if ($roles === []) {
            $this->error(__('commands.assign_role.no_roles'));

            return self::FAILURE;
        }

        /** @var string $roleName */
        $roleName = $this->choice(__('commands.assign_role.ask_role'), $roles);

        $user->syncRoles($roleName);

        $this->info(__('commands.assign_role.success', [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $roleName,
        ]));

        return self::SUCCESS;
    }
}
