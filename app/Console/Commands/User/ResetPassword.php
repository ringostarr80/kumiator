<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use App\Services\User\Contracts\UserPasswordResetterContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Admin-initiierter Passwort-Reset über die Konsole.
 *
 * Die eigentliche Mechanik (Hash, Persistenz, anonymisierter
 * `password_reset`-Audit-Eintrag) liegt im `UserPasswordResetter`-Service.
 * Der Command ist reine Presentation: Eingabe einlesen, Vorbedingungen
 * prüfen, Service aufrufen, Ergebnis ausgeben.
 */
#[Signature('user:reset-password')]
#[Description('Setzt das Passwort eines Benutzers neu')]
class ResetPassword extends Command
{
    public function __construct(private readonly UserPasswordResetterContract $resetter)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.reset_password.title');
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

        /** @var string $password */
        $password = $this->secret(__('commands.reset_password.ask_password'));
        $passwordConfirm = $this->secret(__('commands.reset_password.ask_password_confirm'));

        $validator = Validator::make(
            [
                'password' => $password,
                'password_confirmation' => $passwordConfirm,
            ],
            [
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->resetter->reset($user, $password);

        $this->info(__('commands.reset_password.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
