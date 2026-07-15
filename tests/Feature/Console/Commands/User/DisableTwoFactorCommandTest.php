<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

final class DisableTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';

    public function testTwoFactorCanBeDisabled(): void
    {
        $user = $this->createUserWithTwoFactor();

        $command = $this->artisan('user:disable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.disable_two_factor.confirm_disable'), 'yes')
            ->expectsOutputToContain(
                __('commands.disable_two_factor.success', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function testDisableCanBeCancelled(): void
    {
        $user = $this->createUserWithTwoFactor();

        $command = $this->artisan('user:disable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.disable_two_factor.confirm_disable'), 'no')
            ->expectsOutputToContain(__('commands.common.aborted'))
            ->assertSuccessful()
            ->run();

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);
    }

    public function testDisableWithoutEnabledTwoFactorShowsWarning(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:disable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(
                __('commands.disable_two_factor.not_enabled', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();
    }

    public function testDisableTwoFactorForNonExistentUserFails(): void
    {
        $command = $this->artisan('user:disable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }

    private function createUserWithTwoFactor(): User
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user, force: true);
        $user->forceFill(['two_factor_confirmed_at' => now()])->saveOrFail();

        return $user;
    }
}
