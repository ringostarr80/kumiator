<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testUserCanBeCreatedWithRole(): void
    {
        Role::findOrCreate('member');
        Role::findOrCreate('admin');

        $command = $this->artisan('user:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_user.ask_name'), 'John Doe')
            ->expectsQuestion(__('commands.common.ask_email'), 'john@example.com')
            ->expectsQuestion(__('commands.create_user.ask_password'), 'password123')
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), 'password123')
            ->expectsChoice(__('commands.create_user.ask_role'), 'admin', ['admin', 'member'])
            ->expectsOutputToContain('John Doe')
            ->assertSuccessful()
            ->run();

        $user = User::where('email', 'john@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
    }

    public function testUserCannotBeCreatedWithoutRoles(): void
    {
        $command = $this->artisan('user:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsOutputToContain(__('commands.create_user.no_roles'))
            ->assertFailed()
            ->run();
    }

    public function testUserCreationFailsWithInvalidData(): void
    {
        Role::findOrCreate('member');

        $command = $this->artisan('user:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_user.ask_name'), '')
            ->expectsQuestion(__('commands.common.ask_email'), 'invalid-email')
            ->expectsQuestion(__('commands.create_user.ask_password'), 'short')
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), 'different')
            ->expectsChoice(__('commands.create_user.ask_role'), 'member', ['member'])
            ->assertFailed()
            ->run();
    }
}
