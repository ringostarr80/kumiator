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
 * registrieren. Das wird im Output prominent ausgegeben.
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

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        /** @var ?User $user */
        $user = User::onlyTrashed()->where('email', $email)->first();

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

        if (!$this->confirm(__('commands.restore_user.confirm_restore'))) {
            $this->info(__('commands.restore_user.aborted'));

            return self::SUCCESS;
        }

        // Laravel 13 bietet kein `restoreOrFail()`; `restore()` gibt `false` nur
        // zurück, wenn ein `restoring`-Listener abbricht. In diesem Projekt gibt
        // es derzeit keinen solchen Listener — der Branch ist Defensive-Coding
        // gegen zukünftige Erweiterungen, ohne neue Exception-Typen einzuführen.
        if ($user->restore() === false) {
            $this->error(__('commands.restore_user.failed', ['email' => $email]));

            return self::FAILURE;
        }

        $this->info(__('commands.restore_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
