<?php

declare(strict_types=1);

namespace App\Console\Commands\Role;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

#[Signature('role:delete')]
#[Description('Löscht eine bestehende Rolle')]
class Delete extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.delete_role.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $name */
        $name = $this->ask(__('commands.delete_role.ask_name')) ?? '';

        $role = Role::findByName($name);

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            /** @var \Illuminate\Database\Eloquent\Collection<int, User> $users */
            $users = $role->users()->get();
            $usersWithSingleRole = $users->filter(
                static fn (User $user) => $user->roles()->count() === 1,
            );

            if ($usersWithSingleRole->isNotEmpty()) {
                $this->error(__('commands.delete_role.has_sole_users', [
                    'name' => $name,
                    'count' => $usersWithSingleRole->count(),
                ]));

                return self::FAILURE;
            }
        }

        $this->line(__('commands.delete_role.role_found', ['name' => $name, 'users_count' => $usersCount]));

        if (! $this->confirm(__('commands.delete_role.confirm_delete'))) {
            $this->info(__('commands.delete_role.aborted'));

            return self::SUCCESS;
        }

        $role->deleteOrFail();

        $this->info(__('commands.delete_role.success', ['name' => $name]));

        return self::SUCCESS;
    }
}
