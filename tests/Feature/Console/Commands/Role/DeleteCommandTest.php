<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testRoleCanBeDeletedWhenNoUsersAssigned(): void
    {
        Role::findOrCreate('admin');

        $command = $this->artisan('role:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.delete_role.ask_name'), 'admin')
            ->expectsOutputToContain('admin')
            ->expectsConfirmation(__('commands.delete_role.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertNull(Role::where('name', 'admin')->first());
    }

    public function testRoleCannotBeDeletedWhenUsersHaveOnlyThisRole(): void
    {
        $role = Role::findOrCreate('member');

        $user = User::factory()->create();
        $user->assignRole($role);

        $command = $this->artisan('role:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.delete_role.ask_name'), 'member')
            ->assertFailed()
            ->run();

        $this->assertNotNull(Role::where('name', 'member')->first());
    }

    public function testRoleCanBeDeletedWhenUsersHaveOtherRoles(): void
    {
        $memberRole = Role::findOrCreate('member');
        $adminRole = Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole([$memberRole, $adminRole]);

        $command = $this->artisan('role:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.delete_role.ask_name'), 'member')
            ->expectsOutputToContain('member')
            ->expectsConfirmation(__('commands.delete_role.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertNull(Role::where('name', 'member')->first());

        $freshUser = $user->fresh();
        $this->assertInstanceOf(User::class, $freshUser);
        $this->assertTrue($freshUser->hasRole('admin'));
    }

    public function testRoleDeletionCanBeCancelled(): void
    {
        Role::findOrCreate('admin');

        $command = $this->artisan('role:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.delete_role.ask_name'), 'admin')
            ->expectsOutputToContain('admin')
            ->expectsConfirmation(__('commands.delete_role.confirm_delete'), 'no')
            ->assertSuccessful()
            ->run();

        $this->assertNotNull(Role::where('name', 'admin')->first());
    }
}
