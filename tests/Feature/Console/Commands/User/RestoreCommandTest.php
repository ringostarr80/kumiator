<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class RestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';

    public function testSoftDeletedUserCanBeRestored(): void
    {
        $user = User::factory()->create(['name' => 'John Doe', 'email' => self::TEST_EMAIL]);
        $user->deleteOrFail();

        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain('John Doe')
            ->expectsOutputToContain(__('commands.restore_user.hint'))
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'yes')
            ->assertSuccessful()
            ->run();

        $restored = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
    }

    public function testRestoreCanBeCancelled(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        $user->deleteOrFail();

        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'no')
            ->expectsOutputToContain(__('commands.restore_user.aborted'))
            ->assertSuccessful()
            ->run();

        $stillTrashed = User::onlyTrashed()->where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($stillTrashed);
        $this->assertNotNull($stillTrashed->deleted_at);
    }

    public function testRestoreFailsForActiveUser(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.restore_user.not_trashed', ['email' => self::TEST_EMAIL]))
            ->assertFailed()
            ->run();
    }

    public function testRestoreFailsForUnknownUser(): void
    {
        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.restore_user.not_trashed', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }
}
