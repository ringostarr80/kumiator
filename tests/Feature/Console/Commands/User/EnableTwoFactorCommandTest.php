<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FixedSecretTwoFactorProvider;
use Tests\TestCase;

final class EnableTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';
    private const string TEST_SECRET = 'JBSWY3DPEHPK3PXP';

    public function testTwoFactorCanBeEnabled(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->expectsOutputToContain(
                __('commands.enable_two_factor.success', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNotNull($user->two_factor_confirmed_at);

        // Symmetrie zum UI-Pfad: sowohl `2fa_enabled` (durch
        // `EnableTwoFactorAuthentication`) als auch `2fa_confirmed` (durch
        // `ConfirmTwoFactorAuthentication`) müssen im auth-Log landen.
        $this->assertAuthEventLogged($user, '2fa_enabled');
        $this->assertAuthEventLogged($user, '2fa_confirmed');
    }

    public function testSecretAndQrCodeUrlAreDisplayed(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.enable_two_factor.secret_label'))
            ->expectsOutputToContain(self::TEST_SECRET)
            ->expectsOutputToContain(__('commands.enable_two_factor.qr_code_label'))
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->assertSuccessful()
            ->run();
    }

    public function testRecoveryCodesAreDisplayed(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->expectsOutputToContain(__('commands.enable_two_factor.recovery_codes_label'))
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertCount(8, $user->recoveryCodes());
    }

    public function testInvalidCodeFailsAndCleansUp(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), '000000')
            ->expectsOutputToContain(__('commands.enable_two_factor.invalid_code'))
            ->assertFailed()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);

        // Cleanup-Pfad nutzt jetzt `DisableTwoFactorAuthentication` statt
        // direkten forceFill — der Listener erkennt am ungeänderten
        // `two_factor_confirmed_at` (war null, bleibt null) den Setup-Abbruch
        // und schreibt `2fa_setup_aborted`, NICHT `2fa_disabled`.
        $this->assertAuthEventLogged($user, '2fa_enabled');
        $this->assertAuthEventLogged($user, '2fa_setup_aborted');
        $this->assertAuthEventNotLogged($user, '2fa_disabled');
        $this->assertAuthEventNotLogged($user, '2fa_confirmed');
    }

    public function testAlreadyEnabledTwoFactorShowsWarning(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user, force: true);
        $user->forceFill(['two_factor_confirmed_at' => now()])->saveOrFail();

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(
                __('commands.enable_two_factor.already_enabled', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();
    }

    public function testEnableTwoFactorForNonExistentUserFails(): void
    {
        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }

    private function bindFixedSecretProvider(): void
    {
        $this->app->instance(
            TwoFactorAuthenticationProvider::class,
            new FixedSecretTwoFactorProvider(new Google2FA(), self::TEST_SECRET),
        );
    }

    private function generateValidCode(string $secret): string
    {
        $google2fa = new Google2FA();

        return $google2fa->getCurrentOtp($secret);
    }

    private function assertAuthEventLogged(User $user, string $eventCode): void
    {
        $exists = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $eventCode)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->exists();

        $this->assertTrue(
            $exists,
            sprintf("Auth-Log-Eintrag '%s' wurde erwartet, fehlt aber.", $eventCode),
        );
    }

    private function assertAuthEventNotLogged(User $user, string $eventCode): void
    {
        $exists = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $eventCode)
            ->where('causer_type', $user->getMorphClass())
            ->where('causer_id', $user->getKey())
            ->exists();

        $this->assertFalse(
            $exists,
            sprintf("Auth-Log-Eintrag '%s' hätte NICHT geschrieben werden dürfen.", $eventCode),
        );
    }
}
