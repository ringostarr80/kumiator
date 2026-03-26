<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ResetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';

    public function testPasswordCanBeReset(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
            'password' => Hash::make('old-password'),
        ]);

        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'new-password123')
            ->expectsOutputToContain(__('commands.reset_password.success', [
                'name' => self::TEST_NAME,
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('new-password123', $user->password));
    }

    public function testResetPasswordFailsForNonExistentUser(): void
    {
        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }

    public function testResetPasswordFailsWhenPasswordsDoNotMatch(): void
    {
        User::factory()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'new-password123')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'different-password')
            ->assertFailed()
            ->run();
    }

    public function testResetPasswordFailsWhenPasswordIsTooShort(): void
    {
        User::factory()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), 'short')
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), 'short')
            ->assertFailed()
            ->run();
    }
}
