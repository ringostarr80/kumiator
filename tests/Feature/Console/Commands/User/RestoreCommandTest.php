<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Permission;
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
            ->expectsOutputToContain(__('commands.restore_user.user_found', [
                'name' => 'John Doe',
                'email' => self::TEST_EMAIL,
                'deleted_at' => $user->deleted_at?->format('d.m.Y H:i') ?? '',
            ]))
            ->expectsOutputToContain(__('commands.restore_user.hint'))
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'yes')
            ->expectsOutputToContain(__('commands.restore_user.success', [
                'name' => 'John Doe',
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();

        $restored = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);
    }

    /**
     * Soft-Delete entfernt Rollen-/Permission-Pivots bewusst nicht — eine
     * sensible Direkt-Permission wie `activity-log.view` gilt nach dem
     * Restore sofort wieder. Der Output muss das sichtbar machen, damit der
     * Admin die Wiederherstellung bewusst auch als Privilegien-Restore
     * entscheidet.
     */
    public function testRestoreHintsThatPermissionsApplyAgain(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        Permission::findOrCreate('activity-log.view');
        $user->givePermissionTo('activity-log.view');
        $user->deleteOrFail();

        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.restore_user.permissions_hint'))
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'yes')
            ->assertSuccessful()
            ->run();

        $restored = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($restored);
        $this->assertTrue($restored->hasPermissionTo('activity-log.view'));
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
