<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RoleChangeActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testAssigningRoleCreatesActivityLogEntry(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $user->assignRole('admin');

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('roles', $properties);
        $this->assertIsArray($properties['roles']);
        $this->assertContains('admin', $properties['roles']);
    }

    public function testRemovingRoleCreatesActivityLogEntry(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Activity::query()->delete();

        $user->removeRole('admin');

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('roles', $properties);
        $this->assertIsArray($properties['roles']);
        $this->assertContains('admin', $properties['roles']);
    }

    /**
     * Spatie Permission feuert `RoleAttachedEvent` genau einmal pro `assignRole()`-Aufruf,
     * mit allen Rollen im Payload. Der Listener fasst das zu **einem** Activity-Log-Eintrag
     * mit allen Rollen-Namen zusammen — doppelte Einträge wären ein Hinweis auf doppelte
     * Listener-Registrierung (z. B. Auto-Discovery + explizites `Event::listen()`).
     */
    public function testAssigningMultipleRolesAtOnceLogsSingleEntryWithAllRoleNames(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $user->assignRole('admin', 'member');

        $activities = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->get();

        $this->assertCount(1, $activities);
        $properties = $activities->first()?->properties?->toArray() ?? [];
        $this->assertArrayHasKey('roles', $properties);
        $this->assertIsArray($properties['roles']);
        $this->assertEqualsCanonicalizing(['admin', 'member'], $properties['roles']);
    }

    public function testAssigningRoleAsAuthenticatedActorRecordsCauser(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        Activity::query()->delete();

        $this->actingAs($actor);
        $subject->assignRole('admin');

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->getMorphClass(), $activity->causer_type);
        $this->assertSame($actor->getKey(), $activity->causer_id);
    }

    public function testRemovingRoleAsAuthenticatedActorRecordsCauser(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        $subject->assignRole('admin');
        Activity::query()->delete();

        $this->actingAs($actor);
        $subject->removeRole('admin');

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->getMorphClass(), $activity->causer_type);
        $this->assertSame($actor->getKey(), $activity->causer_id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('member');
        Role::findOrCreate('admin');
    }
}
