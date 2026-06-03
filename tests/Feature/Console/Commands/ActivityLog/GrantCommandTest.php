<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class GrantCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'auditor@example.com';
    private const string TEST_NAME = 'Aud Itor';

    public function testGrantGivesViewPermission(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('activity-log:grant');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(self::TEST_NAME)
            ->assertSuccessful()
            ->run();

        $this->assertTrue($user->fresh()?->hasDirectPermission('activity-log.view'));
    }

    public function testGrantWritesPermissionAttachedAuditEntry(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        Activity::query()->delete();

        $command = $this->artisan('activity-log:grant');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
        // Causer auf der CLI anonymisiert (siehe ConsoleActorContext).
        $this->assertNull($activity->causer_id);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame(['activity-log.view'], $properties['permissions'] ?? null);
    }

    public function testGrantingAnAlreadyGrantedUserIsIdempotentAndDoesNotDuplicateAudit(): void
    {
        $user = User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);
        $user->givePermissionTo('activity-log.view');

        Activity::query()->delete();

        $command = $this->artisan('activity-log:grant');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(__('commands.activity_log_grant.already_granted', [
                'name' => self::TEST_NAME,
                'email' => self::TEST_EMAIL,
            ]))
            ->assertSuccessful()
            ->run();

        $this->assertSame(
            0,
            Activity::query()->where('event', 'permission_attached')->count(),
            'Ein erneuter Grant darf keinen zweiten permission_attached-Eintrag erzeugen.',
        );
    }

    public function testGrantForNonExistentUserFails(): void
    {
        $command = $this->artisan('activity-log:grant');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Liefert die `activity-log.view`-Permission-Definition aus dem
        // produktiven Seeder, damit `givePermissionTo()` sie referenzieren kann.
        $this->seed(RoleSeeder::class);
    }
}
