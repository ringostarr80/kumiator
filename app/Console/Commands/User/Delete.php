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
 * Sessions werden trotzdem hart entfernt: Eine bestehende Browser-Session
 * würde dem soft-deleted User sonst weiterhin Zugriff geben, weil das Cookie
 * gültig bleibt.
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

            $user->deleteOrFail();
        });

        $this->info(__('commands.delete_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
