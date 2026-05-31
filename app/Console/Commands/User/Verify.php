<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use App\Services\User\Contracts\UserEmailVerifierContract;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Admin-initiierte E-Mail-Verifizierung über die Konsole.
 *
 * Die eigentliche Mechanik (Setzen von `email_verified_at`, anonymisierter
 * Audit-Eintrag im `auth`-Log) liegt im `UserEmailVerifier`-Service. Der
 * Command ist reine Presentation: Eingabe einlesen, Vorbedingungen prüfen,
 * Service aufrufen, Ergebnis ausgeben.
 */
#[Signature('user:verify')]
#[Description('Verifiziert die E-Mail-Adresse eines Benutzers')]
class Verify extends Command
{
    public function __construct(private readonly UserEmailVerifierContract $verifier)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $title = __('commands.verify_user.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        /** @var ?User $user */
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if ($user->hasVerifiedEmail()) {
            $this->warn(__('commands.verify_user.already_verified', ['name' => $user->name, 'email' => $email]));

            return self::SUCCESS;
        }

        $this->verifier->verify($user);

        $this->info(__('commands.verify_user.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
