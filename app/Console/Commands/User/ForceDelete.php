<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\UserHardDeleterContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

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
class ForceDelete extends TrashedUserActionCommand
{
    public function __construct(private readonly UserHardDeleterContract $hardDeleter)
    {
        parent::__construct();
    }

    protected function titleKey(): string
    {
        return 'commands.force_delete_user.title';
    }

    protected function notTrashedKey(): string
    {
        return 'commands.force_delete_user.not_trashed';
    }

    protected function userFoundKey(): string
    {
        return 'commands.common.trashed_user_found';
    }

    /**
     * @return list<string>
     */
    protected function warningKeys(): array
    {
        return ['commands.force_delete_user.warning'];
    }

    protected function confirmKey(): string
    {
        return 'commands.force_delete_user.confirm_force_delete';
    }

    protected function abortedKey(): string
    {
        return 'commands.common.aborted';
    }

    protected function successKey(): string
    {
        return 'commands.force_delete_user.success';
    }

    /**
     * @return array<string, string>
     */
    protected function successReplacements(User $user, string $email): array
    {
        return ['email' => $email];
    }

    protected function performAction(User $user): void
    {
        $this->hardDeleter->forceDelete($user, ActivityEvent::ACCOUNT_ADMIN_FORCE_DELETED);
    }
}
