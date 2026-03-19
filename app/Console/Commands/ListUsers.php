<?php

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
        $users = User::orderBy('name')->get(['name', 'email', 'created_at']);

        if ($users->isEmpty()) {
            $this->info(__('commands.list_users.no_users'));

            return self::SUCCESS;
        }

        $this->table(
            [
                __('commands.list_users.header_name'),
                __('commands.list_users.header_email'),
                __('commands.list_users.header_created_at'),
            ],
            $users->map(fn (User $user) => [
                $user->name,
                $user->email,
                $user->created_at?->format('d.m.Y H:i'),
            ])
        );

        $this->line(__('commands.list_users.total', ['count' => $users->count()]));

        return self::SUCCESS;
    }
}
