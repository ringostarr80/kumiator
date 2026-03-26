<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ListUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string DATETIME_FORMAT = 'd.m.Y H:i';

    public function testUsersAreListed(): void
    {
        $role = Role::findOrCreate('admin');
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $user->assignRole($role);

        $command = $this->artisan('user:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'John Doe',
                        'john@example.com',
                        'admin',
                        '✓',
                        $user->created_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->expectsOutputToContain(__('commands.list_users.total', ['count' => 1]))
            ->assertSuccessful()
            ->run();
    }

    public function testUserWithoutRoleShowsDash(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe']);

        $command = $this->artisan('user:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'Jane Doe',
                        $user->email,
                        '—',
                        '✓',
                        $user->created_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->assertSuccessful()
            ->run();
    }

    public function testUnverifiedUserShowsCross(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Unverified User']);

        $command = $this->artisan('user:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'Unverified User',
                        $user->email,
                        '—',
                        '✗',
                        $user->created_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->assertSuccessful()
            ->run();
    }

    public function testNoUsersShowsInfoMessage(): void
    {
        $command = $this->artisan('user:list');
        assert($command instanceof PendingCommand);

        $command
            ->expectsOutputToContain(__('commands.list_users.no_users'))
            ->assertSuccessful()
            ->run();
    }
}
