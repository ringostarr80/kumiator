<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

#[Signature('user:disable-2fa')]
#[Description('Deaktiviert die Zwei-Faktor-Authentifizierung (TOTP) für einen Benutzer')]
class DisableTwoFactor extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DisableTwoFactorAuthentication $disableAction): int
    {
        $title = __('commands.disable_two_factor.title');
        $this->info($title);
        $this->line(str_repeat('-', mb_strlen($title)));

        /** @var string $email */
        $email = $this->ask(__('commands.common.ask_email')) ?? '';

        $user = User::queryByEmail($email)->first();

        if ($user === null) {
            $this->error(__('commands.common.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if (!$user->hasEnabledTwoFactorAuthentication()) {
            $this->warn(__('commands.disable_two_factor.not_enabled', ['name' => $user->name, 'email' => $email]));

            return self::SUCCESS;
        }

        $this->line(__('commands.common.user_found', ['name' => $user->name, 'email' => $email]));

        if (!$this->confirm(__('commands.disable_two_factor.confirm_disable'))) {
            $this->info(__('commands.common.aborted'));

            return self::SUCCESS;
        }

        $disableAction($user);

        $this->info(__('commands.disable_two_factor.success', ['name' => $user->name, 'email' => $email]));

        return self::SUCCESS;
    }
}
