<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

#[Signature('role:list')]
#[Description('Listet alle Rollen auf')]
class ListRoles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $roles = Role::withCount('users')->orderBy('name')->get();

        if ($roles->isEmpty()) {
            $this->info(__('commands.list_roles.no_roles'));

            return self::SUCCESS;
        }

        $this->table(
            [
                __('commands.list_roles.header_name'),
                __('commands.list_roles.header_users_count'),
                __('commands.list_roles.header_created_at'),
            ],
            $roles->map(static fn (Role $role) => [
                $role->name,
                $role->users_count,
                $role->created_at?->format('d.m.Y H:i'),
            ]),
        );

        $this->line(__('commands.list_roles.total', ['count' => $roles->count()]));

        return self::SUCCESS;
    }
}
