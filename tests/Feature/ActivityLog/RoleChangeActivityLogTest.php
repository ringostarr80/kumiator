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

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('member');
        Role::findOrCreate('admin');
    }
}
