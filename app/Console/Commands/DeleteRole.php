<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

#[Signature('role:delete')]
#[Description('Löscht eine bestehende Rolle')]
class DeleteRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.delete_role.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $name = $this->ask(__('commands.delete_role.ask_name')) ?? '';
        assert(is_string($name));

        $role = Role::findByName($name);

        $usersCount = $role->users()->count();

        $this->line(__('commands.delete_role.role_found', ['name' => $name, 'users_count' => $usersCount]));

        if (!$this->confirm(__('commands.delete_role.confirm_delete'))) {
            $this->info(__('commands.delete_role.aborted'));

            return self::SUCCESS;
        }

        $role->delete();

        $this->info(__('commands.delete_role.success', ['name' => $name]));

        return self::SUCCESS;
    }
}
