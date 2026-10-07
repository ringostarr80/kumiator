<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AssignCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';

    public function testRoleCanBeAssignedToUser(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        Role::findOrCreate('admin');
        Role::findOrCreate('member');

        $command = $this->artisan('role:assign');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsChoice(__('commands.assign_role.ask_role'), 'admin', ['admin', 'member'])
            ->expectsOutputToContain($user->name)
            ->assertSuccessful()
            ->run();

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertTrue($freshUser->hasRole('admin'));
    }

    public function testAssignRoleFailsForNonExistentUser(): void
    {
        Role::findOrCreate('admin');

        $command = $this->artisan('role:assign');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }

    public function testAssignRoleFailsWhenNoRolesExist(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        $command = $this->artisan('role:assign');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.assign_role.no_roles'))
            ->assertFailed()
            ->run();
    }

    public function testAssignRoleReplacesExistingRole(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('member');

        $user = User::factory()->create(['email' => self::TEST_EMAIL]);
        $user->assignRole('member');

        $command = $this->artisan('role:assign');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsChoice(__('commands.assign_role.ask_role'), 'admin', ['admin', 'member'])
            ->assertSuccessful()
            ->run();

        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser);
        $this->assertTrue($freshUser->hasRole('admin'));
        $this->assertFalse($freshUser->hasRole('member'));
    }

    public function testTitleIsUnderlined(): void
    {
        // Unter `de`, weil deutsche Titel Umlaute tragen können: An ihnen zählte
        // `strlen` Bytes statt Zeichen, und die Linie geriete zu lang.
        $this->app->setLocale('de');
        $title = __('commands.assign_role.title');

        $command = $this->artisan('role:assign');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutput($title)
            ->expectsOutput(str_repeat('-', mb_strlen($title)))
            // Die unbekannte E-Mail ist nur der kürzeste Weg bis zum Ende des Commands.
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->assertFailed()
            ->run();
    }
}
