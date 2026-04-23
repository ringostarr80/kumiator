<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Administrativer Lösch-Pfad für Benutzer.
 *
 * Im Gegensatz zum Self-Delete (siehe `App\Actions\Jetstream\DeleteUser`,
 * dort Hard-Delete via `forceDelete()` für DSGVO-konformes „Recht auf
 * Vergessen") nutzt dieser Command einen Soft-Delete (`deleteOrFail()`),
 * um die fachliche Historie — und insbesondere die Activity-Log-Verweise
 * auf den gelöschten User — zu erhalten.
 *
 * Sessions, Passkey-Credentials **und** Sanctum-API-Tokens werden trotzdem
 * hart entfernt: Alle drei sind aktive Zugriffsmittel, die dem soft-deleted
 * User sonst nach einem späteren `restore()` wieder Zugang verschaffen würden
 * (Cookie bleibt gültig, Passkey bleibt kryptographisch gültig, Token-Hash
 * liegt unverändert in der `personal_access_tokens`-Tabelle). Anders als beim
 * Self-Delete laufen die Passkey-Löschungen hier **mit** Eloquent-Events durch
 * — die Widerrufe sollen im Activity-Log des Admin-Pfads dokumentiert bleiben
 * (Causer = handelnder Admin).
 *
 * Hinweis zum Session-Treiber: Die explizite Session-Löschung wirkt nur bei
 * `session.driver = database` (aktueller Projekt-Default). Bei Redis/File/Cookie
 * bleiben Session-Payloads im Backend liegen, bis ihre TTL abläuft. Für den
 * Auth-Schutz reicht das, weil der `SoftDeletes`-Global-Scope greift und
 * `EloquentUserProvider::retrieveById()` keinen User mehr liefert. Wird der
 * Treiber gewechselt, muss diese Annahme neu bewertet werden (ggf. treiber-
 * spezifisches Purge, DSGVO-Sicht auf Session-Payloads).
 */
#[Signature('user:delete')]
#[Description('Löscht einen bestehenden Benutzer')]
class Delete extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.delete_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        $email = $this->ask(__('commands.common.ask_email')) ?? '';
        assert(is_string($email));

        /** @var ?User $user */
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.delete_user.user_found', ['name' => $user->name, 'email' => $email]));

        if (!$this->confirm(__('commands.delete_user.confirm_delete'))) {
            $this->info(__('commands.delete_user.aborted'));

            return self::SUCCESS;
        }

        DB::transaction(static function () use ($user): void {
            if (Config::string('session.driver') === 'database') {
                DB::table(Config::string('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->tokens->each->deleteOrFail();
            $user->passkeyCredentials->each->deleteOrFail();

            $user->deleteOrFail();
        });

        $this->info(__('commands.delete_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
