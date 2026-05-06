<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use App\Services\User\Contracts\UserHardDeleterContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * DSGVO-konformer Hard-Delete für einen bereits soft-deleted Benutzer.
 *
 * Der Command verlangt explizit, dass der Ziel-User vorher per `user:delete`
 * soft-deleted wurde — der Soft-Delete-Pfad ist damit das Sicherheitsnetz,
 * über das im Zweifel `user:restore` zurückrollen kann. Aktive User können
 * nicht direkt force-gelöscht werden.
 *
 * Die eigentliche Mechanik (Tokens, Passkeys, Sessions, Rollen-Pivots,
 * Activity-Log-Purge, anonymer Audit-Eintrag) liegt im `UserHardDeleter`-
 * Service, den auch der Self-Delete-Pfad (`App\Actions\Jetstream\DeleteUser`)
 * nutzt — der Event-Name `account_admin_force_deleted` unterscheidet die
 * beiden Pfade im verbleibenden Audit-Trail.
 */
#[Signature('user:force-delete')]
#[Description('Löscht einen bereits soft-deleted Benutzer endgültig (DSGVO)')]
class ForceDelete extends Command
{
    public function __construct(private readonly UserHardDeleterContract $hardDeleter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $title = __('commands.force_delete_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        /** @var ?User $user */
        $user = User::onlyTrashed()->where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.force_delete_user.not_trashed', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.force_delete_user.user_found', [
            'name' => $user->name,
            'email' => $email,
            'deleted_at' => $user->deleted_at?->format('d.m.Y H:i') ?? '—',
        ]));
        $this->warn(__('commands.force_delete_user.warning'));

        if (!$this->confirm(__('commands.force_delete_user.confirm_force_delete'))) {
            $this->info(__('commands.force_delete_user.aborted'));

            return self::SUCCESS;
        }

        $this->hardDeleter->forceDelete(
            $user,
            'account_admin_force_deleted',
            __('app.activity_account_admin_force_deleted'),
        );

        $this->info(__('commands.force_delete_user.success', ['email' => $email]));

        return self::SUCCESS;
    }
}
