<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class ForceDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';

    public function testSoftDeletedUserCanBeForceDeleted(): void
    {
        $user = User::factory()->create(['name' => 'John Doe', 'email' => self::TEST_EMAIL]);
        $user->deleteOrFail();

        $command = $this->artisan('user:force-delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain('John Doe')
            ->expectsOutputToContain(__('commands.force_delete_user.warning'))
            ->expectsConfirmation(__('commands.force_delete_user.confirm_force_delete'), 'yes')
            ->expectsOutputToContain(__('commands.force_delete_user.success', ['email' => self::TEST_EMAIL]))
            ->assertSuccessful()
            ->run();

        $this->assertNull(User::withTrashed()->where('email', self::TEST_EMAIL)->first());
    }

    public function testForceDeleteCanBeCancelled(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        $user->deleteOrFail();

        $command = $this->artisan('user:force-delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.force_delete_user.confirm_force_delete'), 'no')
            ->expectsOutputToContain(__('commands.common.aborted'))
            ->assertSuccessful()
            ->run();

        $stillTrashed = User::onlyTrashed()->where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($stillTrashed);
    }

    public function testForceDeleteRefusesActiveUser(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:force-delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.force_delete_user.not_trashed', ['email' => self::TEST_EMAIL]))
            ->assertFailed()
            ->run();

        $this->assertNotNull(User::where('email', self::TEST_EMAIL)->first());
    }

    public function testForceDeleteFailsForUnknownUser(): void
    {
        $command = $this->artisan('user:force-delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.force_delete_user.not_trashed', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }

    public function testForceDeleteWritesAnonymisedAuditEntry(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        $user->deleteOrFail();
        Activity::query()->delete();

        $command = $this->artisan('user:force-delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.force_delete_user.confirm_force_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'account_admin_force_deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_account_admin_force_deleted'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame([], $properties);
    }
}
