<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Role;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CreateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testRoleCanBeCreated(): void
    {
        $command = $this->artisan('role:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'admin')
            ->expectsOutputToContain(__('commands.create_role.success', ['name' => 'admin']))
            ->assertSuccessful()
            ->run();

        $this->assertTrue(Role::where('name', 'admin')->exists());
    }

    public function testRoleCreationFailsWithEmptyName(): void
    {
        $command = $this->artisan('role:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), '')
            ->assertFailed()
            ->run();
    }

    public function testRoleCreationFailsWithDuplicateName(): void
    {
        Role::findOrCreate('admin');

        $command = $this->artisan('role:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'admin')
            ->assertFailed()
            ->run();
    }
}
