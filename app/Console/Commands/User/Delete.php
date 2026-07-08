<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Administrativer Lösch-Pfad für Benutzer.
 *
 * Im Gegensatz zum Self-Delete (siehe `App\Actions\Jetstream\DeleteUser`,
 * dort Hard-Delete via `forceDelete()` für DSGVO-konformes „Recht auf
 * Vergessen") nutzt dieser Command einen Soft-Delete, um die fachliche
 * Historie — und insbesondere die Activity-Log-Verweise auf den gelöschten
 * User — zu erhalten.
 *
 * Sessions, Sanctum-API-Tokens und Passkey-Credentials werden trotzdem hart
 * entfernt: Alle drei sind aktive Zugriffsmittel, die dem soft-deleted User
 * sonst nach einem späteren `restore()` wieder Zugang verschaffen würden.
 * Die eigentliche Mechanik liegt im `UserSoftDeleter`-Service — dort auch
 * die Audit-Symmetrie zum UI-Pfad (`api_token_revoked`-Eintrag pro Token,
 * Passkey-Removed via `LogsActivity`-Trait). Dieser Command ist reine
 * Presentation: Eingabe, Bestätigung, Service-Aufruf, Ergebnis.
 *
 * Hinweis zum Session-Treiber: Die explizite Session-Löschung wirkt nur bei
 * `session.driver = database` (aktueller Projekt-Default). Bei Redis/File/Cookie
 * bleiben Session-Payloads im Backend liegen, bis ihre TTL abläuft. Für den
 * Auth-Schutz reicht das, weil der `SoftDeletes`-Global-Scope greift und
 * `EloquentUserProvider::retrieveById()` keinen User mehr liefert.
 */
#[Signature('user:delete')]
#[Description('Löscht einen bestehenden Benutzer')]
class Delete extends Command
{
    public function __construct(private readonly UserSoftDeleterContract $softDeleter)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.delete_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        /** @var ?User $user */
        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.delete_user.user_found', ['name' => $user->name, 'email' => $email]));

        if (!$this->confirm(__('commands.delete_user.confirm_delete'))) {
            $this->info(__('commands.delete_user.aborted'));

            return self::SUCCESS;
        }

        $this->softDeleter->softDelete($user);

        $this->info(__('commands.delete_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
