<?php

declare(strict_types=1);

namespace App\Console\Commands\User;

use App\Models\User;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

#[Signature('user:enable-2fa')]
#[Description('Aktiviert die Zwei-Faktor-Authentifizierung (TOTP) für einen Benutzer')]
class EnableTwoFactor extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EnableTwoFactorAuthentication $enableAction, TwoFactorAuthenticationProvider $provider): int
    {
        $title = __('commands.enable_two_factor.title');
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

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->warn(__('commands.enable_two_factor.already_enabled', ['name' => $user->name, 'email' => $email]));

            return self::SUCCESS;
        }

        $enableAction($user, force: true);
        $user->refresh();

        $decryptedSecret = $this->getDecryptedSecret($user);
        $this->displaySetupInformation($decryptedSecret, $user);

        $code = $this->ask(__('commands.enable_two_factor.ask_code')) ?? '';
        assert(is_string($code));

        if (!$provider->verify($decryptedSecret, $code)) {
            $this->error(__('commands.enable_two_factor.invalid_code'));
            $this->resetTwoFactor($user);

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => Carbon::now(),
        ])->saveOrFail();

        $this->info(__('commands.enable_two_factor.success', ['name' => $user->name, 'email' => $email]));
        $this->displayRecoveryCodes($user);

        return self::SUCCESS;
    }

    private function getDecryptedSecret(User $user): string
    {
        $twoFactorSecret = $user->two_factor_secret;
        assert(is_string($twoFactorSecret));

        $decrypted = Fortify::currentEncrypter()->decrypt($twoFactorSecret);
        assert(is_string($decrypted));

        return $decrypted;
    }

    private function displaySetupInformation(string $decryptedSecret, User $user): void
    {
        $this->newLine();
        $this->info(__('commands.enable_two_factor.qr_code_label'));
        $this->displayQrCode($user->twoFactorQrCodeUrl());
        $this->newLine();

        $this->info(__('commands.enable_two_factor.secret_label'));
        $this->line($decryptedSecret);
        $this->newLine();
    }

    private function displayQrCode(string $url): void
    {
        $qrCode = Encoder::encode($url, ErrorCorrectionLevel::L());
        $matrix = $qrCode->getMatrix();
        $width = $matrix->getWidth();
        $height = $matrix->getHeight();

        $padding = 1;

        for ($y = -$padding; $y < $height + $padding; $y += 2) {
            $line = '';

            for ($x = -$padding; $x < $width + $padding; $x++) {
                $top = $y >= 0 && $y < $height && $x >= 0 && $x < $width && $matrix->get($x, $y) === 1;
                $bottom = $y + 1 < $height
                    && $x >= 0
                    && $x < $width
                    && $matrix->get($x, $y + 1) === 1;

                $line .= match (true) {
                    $top && $bottom => ' ',
                    $top => '▄',
                    $bottom => '▀',
                    default => '█',
                };
            }

            $this->line($line);
        }
    }

    private function displayRecoveryCodes(User $user): void
    {
        $this->newLine();
        $this->info(__('commands.enable_two_factor.recovery_codes_label'));

        /** @var list<string> $recoveryCodes */
        $recoveryCodes = $user->recoveryCodes();

        foreach ($recoveryCodes as $code) {
            $this->line('  ' . $code);
        }
    }

    private function resetTwoFactor(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->saveOrFail();
    }
}
