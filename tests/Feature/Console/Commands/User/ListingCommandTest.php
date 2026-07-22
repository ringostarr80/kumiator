<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ListingCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string ACTIVE_USER_NAME = 'Active User';
    private const string DATETIME_FORMAT = 'd.m.Y H:i';
    private const string DELETED_USER_NAME = 'Deleted User';

    public function testUsersAreListed(): void
    {
        $role = Role::findOrCreate('admin');
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $user->assignRole($role);

        $command = $this->artisan('user:list');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'John Doe',
                        'john@example.com',
                        'admin',
                        '✓',
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
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'Jane Doe',
                        $user->email,
                        '—',
                        '✓',
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
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'Unverified User',
                        $user->email,
                        '—',
                        '✗',
                        '✓',
                        $user->created_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->assertSuccessful()
            ->run();
    }

    public function testUnapprovedUserShowsCross(): void
    {
        $user = User::factory()->unapproved()->create(['name' => 'Unapproved User']);

        $command = $this->artisan('user:list');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                ],
                [
                    [
                        'Unapproved User',
                        $user->email,
                        '—',
                        '✓',
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
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutputToContain(__('commands.list_users.no_users'))
            ->assertSuccessful()
            ->run();
    }

    public function testSoftDeletedUsersAreHiddenByDefault(): void
    {
        User::factory()->create(['name' => self::ACTIVE_USER_NAME]);
        $deleted = User::factory()->create(['name' => self::DELETED_USER_NAME]);
        $deleted->deleteOrFail();

        $command = $this->artisan('user:list');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsOutputToContain(__('commands.list_users.total', ['count' => 1]))
            ->doesntExpectOutputToContain(self::DELETED_USER_NAME)
            ->assertSuccessful()
            ->run();
    }

    public function testWithTrashedFlagShowsActiveAndDeletedUsers(): void
    {
        $active = User::factory()->create(['name' => self::ACTIVE_USER_NAME]);
        $deleted = User::factory()->create(['name' => self::DELETED_USER_NAME]);
        $deleted->deleteOrFail();
        $deleted->refresh();

        $command = $this->artisan('user:list', ['--with-trashed' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                    __('commands.list_users.header_deleted_at'),
                ],
                [
                    [
                        self::ACTIVE_USER_NAME,
                        $active->email,
                        '—',
                        '✓',
                        '✓',
                        $active->created_at?->format(self::DATETIME_FORMAT),
                        '—',
                    ],
                    [
                        '🗑 Deleted User',
                        $deleted->email,
                        '—',
                        '✓',
                        '✓',
                        $deleted->created_at?->format(self::DATETIME_FORMAT),
                        $deleted->deleted_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->expectsOutputToContain(__('commands.list_users.total', ['count' => 2]))
            ->assertSuccessful()
            ->run();
    }

    public function testOnlyTrashedFlagShowsOnlyDeletedUsers(): void
    {
        User::factory()->create(['name' => self::ACTIVE_USER_NAME]);
        $deleted = User::factory()->create(['name' => self::DELETED_USER_NAME]);
        $deleted->deleteOrFail();
        $deleted->refresh();

        $command = $this->artisan('user:list', ['--only-trashed' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsTable(
                [
                    __('commands.list_users.header_name'),
                    __('commands.list_users.header_email'),
                    __('commands.list_users.header_role'),
                    __('commands.list_users.header_verified'),
                    __('commands.list_users.header_approved'),
                    __('commands.list_users.header_created_at'),
                    __('commands.list_users.header_deleted_at'),
                ],
                [
                    [
                        '🗑 Deleted User',
                        $deleted->email,
                        '—',
                        '✓',
                        '✓',
                        $deleted->created_at?->format(self::DATETIME_FORMAT),
                        $deleted->deleted_at?->format(self::DATETIME_FORMAT),
                    ],
                ],
            )
            ->expectsOutputToContain(__('commands.list_users.total', ['count' => 1]))
            ->doesntExpectOutputToContain(self::ACTIVE_USER_NAME)
            ->assertSuccessful()
            ->run();
    }
}
