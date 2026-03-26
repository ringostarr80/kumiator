<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class DeleteUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';

    public function testUserCanBeDeleted(): void
    {
        User::factory()->create(['name' => 'John Doe', 'email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain('John Doe')
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertNull(User::where('email', self::TEST_EMAIL)->first());
    }

    public function testUserDeletionCanBeCancelled(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'no')
            ->expectsOutputToContain(__('commands.delete_user.aborted'))
            ->assertSuccessful()
            ->run();

        $this->assertNotNull(User::where('email', self::TEST_EMAIL)->first());
    }

    public function testDeleteUserFailsForNonExistentUser(): void
    {
        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }
}
