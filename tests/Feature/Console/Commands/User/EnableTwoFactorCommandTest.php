<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Mockery\MockInterface;
use Tests\TestCase;

final class EnableTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';
    private const string VALID_CODE = '123456';

    public function testTwoFactorCanBeEnabled(): void
    {
        $this->mockTwoFactorProvider(verifyResult: true);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), self::VALID_CODE)
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
    }

    public function testSecretAndQrCodeUrlAreDisplayed(): void
    {
        $this->mockTwoFactorProvider(verifyResult: true);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.enable_two_factor.secret_label'))
            ->expectsOutputToContain('TESTSECRETKEY123')
            ->expectsOutputToContain(__('commands.enable_two_factor.qr_code_label'))
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), self::VALID_CODE)
            ->assertSuccessful()
            ->run();
    }

    public function testRecoveryCodesAreDisplayed(): void
    {
        $this->mockTwoFactorProvider(verifyResult: true);

        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), self::VALID_CODE)
            ->expectsOutputToContain(__('commands.enable_two_factor.recovery_codes_label'))
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertCount(8, $user->recoveryCodes());
    }

    public function testInvalidCodeFailsAndCleansUp(): void
    {
        $this->mockTwoFactorProvider(verifyResult: false);

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
    }

    public function testAlreadyEnabledTwoFactorShowsWarning(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user, force: true);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

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

    private function mockTwoFactorProvider(bool $verifyResult): void
    {
        $this->mock(
            TwoFactorAuthenticationProvider::class,
            static function (MockInterface $mock) use ($verifyResult): void {
                /** @phpstan-ignore method.notFound */
                $mock->shouldReceive('generateSecretKey')->andReturn('TESTSECRETKEY123');
                /** @phpstan-ignore method.notFound */
                $mock->shouldReceive('qrCodeUrl')->andReturn(
                    'otpauth://totp/Test:john@example.com?secret=TESTSECRETKEY123',
                );
                /** @phpstan-ignore method.notFound */
                $mock->shouldReceive('verify')->andReturn($verifyResult);
            },
        );
    }
}
