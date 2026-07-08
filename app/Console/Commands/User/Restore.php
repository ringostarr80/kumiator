<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
class Restore extends Command
{
    public function handle(): int
    {
        $title = __('commands.restore_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        /** @var ?User $user */
        $user = User::queryByEmail($email)->onlyTrashed()->first();

        if ($user === null) {
            // Aus Admin-Sicht egal, ob der User gar nicht existiert oder
            // einfach nicht soft-deleted ist — beides verhindert ein
            // sinnvolles Restore. Eine spezifische „existiert nicht"-Meldung
            // würde nur die Existenz aktiver Konten leaken.
            $this->error(__('commands.restore_user.not_trashed', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.restore_user.user_found', [
            'name' => $user->name,
            'email' => $email,
            'deleted_at' => $user->deleted_at?->format('d.m.Y H:i') ?? '—',
        ]));
        $this->warn(__('commands.restore_user.hint'));
        $this->warn(__('commands.restore_user.permissions_hint'));

        if (!$this->confirm(__('commands.restore_user.confirm_restore'))) {
            $this->info(__('commands.restore_user.aborted'));

            return self::SUCCESS;
        }

        $user->restore();

        $this->info(__('commands.restore_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
