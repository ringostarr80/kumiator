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

        // Auf dem Stand von jetzt, nicht dem vom Beginn des Dialogs: Wer den
        // Passwort-Login schließt, während der Admin die Passwörter tippt, tut
        // das bei Verdacht — und bekäme ihn sonst ungefragt und ohne Hinweis mit
        // dem Passwort wieder geöffnet, das der Admin gleich durchgibt.
        $wasPasswordLoginDisabled = $user->refresh()->isPasswordLoginDisabled();

        // Die Frage steht vor dem Reset, damit die Antwort noch etwas ändern kann.
        // Enter lässt die Sperre stehen, weil nur diese Richtung umkehrbar ist: Ein
        // versehentliches Nein korrigiert der nächste Lauf dieses Befehls, ein
        // versehentliches Ja nur der Kontoinhaber selbst in seinem Profil.
        $reenablePasswordLogin = !$wasPasswordLoginDisabled
            || $this->confirm(__('commands.reset_password.confirm_reenable_password_login'));

        $this->resetter->reset($user, $password, $reenablePasswordLogin);

        $this->info(__('commands.reset_password.success', ['name' => $user->name, 'email' => $email]));

        if ($wasPasswordLoginDisabled) {
            $this->warn($reenablePasswordLogin
                ? __('commands.reset_password.password_login_reenabled')
                : __('commands.reset_password.password_login_kept_disabled'));
        }

        return self::SUCCESS;
    }
}
