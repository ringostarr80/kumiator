<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ListingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string DATETIME_FORMAT = 'd.m.Y H:i';

    public function testRolesAreListed(): void
    {
        $admin = Role::findOrCreate('admin');
        $member = Role::findOrCreate('member');
        User::factory()->create()->assignRole($admin);

        $command = $this->artisan('role:list');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_roles.header_name'),
                    __('commands.list_roles.header_users_count'),
                    __('commands.list_roles.header_created_at'),
                ],
                [
                    ['admin', 1, $admin->created_at?->format(self::DATETIME_FORMAT)],
                    ['member', 0, $member->created_at?->format(self::DATETIME_FORMAT)],
                ],
            )
            ->expectsOutputToContain(__('commands.list_roles.total', ['count' => 2]))
            ->assertSuccessful()
            ->run();
    }

    public function testNoRolesShowsInfoMessage(): void
    {
        $command = $this->artisan('role:list');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutputToContain(__('commands.list_roles.no_roles'))
            ->doesntExpectOutputToContain(__('commands.list_roles.total', ['count' => 0]))
            ->assertSuccessful()
            ->run();
    }
}
