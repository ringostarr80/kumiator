<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'user:list'
    . ' {--with-trashed : Auch soft-deleted Benutzer mit anzeigen}'
    . ' {--only-trashed : Ausschließlich soft-deleted Benutzer anzeigen}',
)]
#[Description('Listet alle Benutzer auf')]
class Listing extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $onlyTrashed = (bool) $this->option('only-trashed');
        $withTrashed = $onlyTrashed || (bool) $this->option('with-trashed');

        $columns = ['id', 'name', 'email', 'email_verified_at', 'approved_at', 'created_at'];

        if ($withTrashed) {
            $columns[] = 'deleted_at';
        }

        $query = User::query()->with('roles')->orderBy('name');

        if ($onlyTrashed) {
            $query->onlyTrashed();
        } elseif ($withTrashed) {
            $query->withTrashed();
        }

        $users = $query->get($columns);

        if ($users->isEmpty()) {
            $this->info(__('commands.list_users.no_users'));

            return self::SUCCESS;
        }

        $headers = [
            __('commands.list_users.header_name'),
            __('commands.list_users.header_email'),
            __('commands.list_users.header_role'),
            __('commands.list_users.header_verified'),
            __('commands.list_users.header_approved'),
            __('commands.list_users.header_created_at'),
        ];

        if ($withTrashed) {
            $headers[] = __('commands.list_users.header_deleted_at');
        }

        $this->table(
            $headers,
            $users->map(static function (User $user) use ($withTrashed): array {
                $row = [
                    $user->trashed() ? '🗑 ' . $user->name : $user->name,
                    $user->email,
                    $user->roles->pluck('name')->first() ?? '—',
                    $user->email_verified_at !== null ? '✓' : '✗',
                    $user->approved_at !== null ? '✓' : '✗',
                    $user->created_at?->format('d.m.Y H:i'),
                ];

                if ($withTrashed) {
                    $row[] = $user->deleted_at?->format('d.m.Y H:i') ?? '—';
                }

                return $row;
            }),
        );

        $this->line(__('commands.list_users.total', ['count' => $users->count()]));

        return self::SUCCESS;
    }
}
