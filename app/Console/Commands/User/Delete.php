<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Console\Commands\Command;
use App\Models\User;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Administrativer Lösch-Pfad für Benutzer.
 *
 * Im Gegensatz zum Self-Delete (siehe `App\Actions\Jetstream\DeleteUser`,
 * dort Hard-Delete via `forceDelete()` für DSGVO-konformes „Recht auf
 * Vergessen") nutzt dieser Command einen Soft-Delete, um die fachliche
 * Historie — und insbesondere die Activity-Log-Verweise auf den gelöschten
 * User — zu erhalten.
 *
 * Was dabei an Zugriffsmitteln fällt und warum, legt der
 * `UserSoftDeleterContract` fest. Dieser Command ist reine Presentation:
 * Eingabe, Bestätigung, Service-Aufruf, Ergebnis.
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

        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $this->line(__('commands.common.user_found', ['name' => $user->name, 'email' => $email]));

        if (!$this->confirm(__('commands.delete_user.confirm_delete'))) {
            $this->info(__('commands.common.aborted'));

            return self::SUCCESS;
        }

        try {
            $this->softDeleter->softDelete($user);
        } catch (ModelNotFoundException) {
            // An der Rückfrage kann ein anderer Admin schneller gewesen sein.
            // Findet das Löschen das aktive Konto deshalb nicht mehr, ist es
            // für diesen Admin derselbe Fall wie oben.
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $this->info(__('commands.delete_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
