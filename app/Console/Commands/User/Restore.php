<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * Stellt einen administrativ soft-deleted Benutzer wieder her.
 *
 * Pendant zum admin-initiierten Soft-Delete (`user:delete`). Tokens, Passkeys
 * und Sessions wurden dort hart gelöscht — sie kommen beim Restore NICHT
 * zurück; der User muss sich neu anmelden und ggf. seine Passkeys neu
 * registrieren. Rollen und Direkt-Permissions blieben dagegen erhalten und
 * gelten sofort wieder. Beides wird im Output prominent ausgegeben, damit
 * der Admin die Wiederherstellung bewusst auch als Privilegien-Restore
 * entscheidet.
 *
 * Activity-Log: Spatie schreibt das `restored`-Event automatisch via dem
 * `LogsActivity`-Trait des Users — kein expliziter Audit-Eintrag nötig.
 */
#[Signature('user:restore')]
#[Description('Stellt einen soft-deleted Benutzer wieder her')]
class Restore extends TrashedUserActionCommand
{
    protected function titleKey(): string
    {
        return 'commands.restore_user.title';
    }

    protected function notTrashedKey(): string
    {
        return 'commands.restore_user.not_trashed';
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
        return [
            'commands.restore_user.hint',
            'commands.restore_user.permissions_hint',
        ];
    }

    protected function confirmKey(): string
    {
        return 'commands.restore_user.confirm_restore';
    }

    protected function abortedKey(): string
    {
        return 'commands.common.aborted';
    }

    protected function successKey(): string
    {
        return 'commands.restore_user.success';
    }

    /**
     * @return array<string, string>
     */
    protected function successReplacements(User $user, string $email): array
    {
        return ['name' => $user->name, 'email' => $email];
    }

    protected function performAction(User $user): void
    {
        $user->restore();
    }
}
