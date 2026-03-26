<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:list')]
#[Description('Listet alle Benutzer auf')]
class ListUsers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $users = User::with('roles')->orderBy('name')->get(
            ['id', 'name', 'email', 'email_verified_at', 'approved_at', 'created_at'],
        );

        if ($users->isEmpty()) {
            $this->info(__('commands.list_users.no_users'));

            return self::SUCCESS;
        }

        $this->table(
            [
                __('commands.list_users.header_name'),
                __('commands.list_users.header_email'),
                __('commands.list_users.header_role'),
                __('commands.list_users.header_verified'),
                __('commands.list_users.header_approved'),
                __('commands.list_users.header_created_at'),
            ],
            $users->map(static fn (User $user) => [
                $user->name,
                $user->email,
                $user->roles->pluck('name')->first() ?? '—',
                $user->email_verified_at !== null ? '✓' : '✗',
                $user->approved_at !== null ? '✓' : '✗',
                $user->created_at?->format('d.m.Y H:i'),
            ]),
        );

        $this->line(__('commands.list_users.total', ['count' => $users->count()]));

        return self::SUCCESS;
    }
}
