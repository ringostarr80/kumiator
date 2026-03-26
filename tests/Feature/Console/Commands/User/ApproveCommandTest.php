<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class ApproveCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';

    public function testUserCanBeApproved(): void
    {
        $user = User::factory()->unapproved()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.approve_user.success', [
                'name' => $user->name,
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();

        $this->assertNotNull($user->fresh()?->approved_at);
    }

    public function testAlreadyApprovedUserShowsWarning(): void
    {
        $user = User::factory()->create([
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.approve_user.already_approved', [
                'name' => $user->name,
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();
    }

    public function testApproveNonExistentUserFails(): void
    {
        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }
}
