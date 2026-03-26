<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ListRolesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testRolesAreListed(): void
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('member');

        $command = $this->artisan('role:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsOutputToContain('admin')
            ->expectsOutputToContain('member')
            ->expectsOutputToContain(__('commands.list_roles.total', ['count' => 2]))
            ->assertSuccessful()
            ->run();
    }

    public function testNoRolesShowsInfoMessage(): void
    {
        $command = $this->artisan('role:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsOutputToContain(__('commands.list_roles.no_roles'))
            ->assertSuccessful()
            ->run();
    }
}
