<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Schließt die Audit-Lücke beim Cascade-Detach: Der FK auf
 * `model_has_roles.role_id` ist mit `cascadeOnDelete()` definiert, also
 * löscht die DB beim `roles`-DELETE die Pivot-Zeilen direkt — Spatie
 * sieht das nicht, kein `RoleDetachedEvent`, kein Log-Eintrag für die
 * betroffenen User. `RoleLifecycleObserver::deleting()` detacht die
 * User vorher via `removeRole()`, sodass Spatie das Event sauber
 * feuert und der bestehende `LogRoleChangeListener` pro User einen
 * `role_detached`-Eintrag schreibt.
 */
final class RoleDeleteCascadeActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testDeletingRoleWithAssignedUserWritesRoleDetachedActivity(): void
    {
        $user = User::factory()->create();
        Role::create(['name' => 'member']);
        $role = Role::create(['name' => 'editor']);
        // Zweite Rolle, damit der Pre-Check im `role:delete`-Command für
        // andere Pfade analog nicht greifen würde — hier rufen wir das
        // Delete direkt auf, der Observer ist Aufruf-quellen-agnostisch.
        $user->assignRole('member');
        $user->assignRole($role);

        Activity::query()->delete();

        $role->deleteOrFail();

        $detachActivity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $detachActivity,
            'Cascade-Detach muss als `role_detached`-Eintrag mit dem User '
            . 'als Subject im Activity-Log auftauchen.',
        );

        $properties = $detachActivity->properties?->toArray() ?? [];
        $this->assertSame(['editor'], $properties['roles'] ?? null);

        // Pivot ist tatsächlich weg (Sanity-Check, dass der Detach-Pfad
        // nicht nur loggt, sondern fachlich auch das Richtige tut).
        $this->assertFalse($user->fresh()?->hasRole('editor'));
    }

    public function testDeletingRoleWithSoftDeletedAssignedUserWritesRoleDetachedActivity(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'editor']);
        $user->assignRole($role);

        // Soft-Delete lässt die Rollen-Pivots bewusst bestehen (siehe
        // `UserSoftDeleter`): der getrashte Inhaber hält die Rolle weiter, der
        // `SoftDeletingScope` blendet ihn aber aus `$role->users()` aus.
        $user->deleteOrFail();

        Activity::query()->delete();

        $role->deleteOrFail();

        $detachActivity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $detachActivity,
            'Auch ein soft-gelöschter Rollen-Inhaber muss beim Cascade-Detach '
            . 'einen `role_detached`-Eintrag erhalten — sonst fehlt nach einem '
            . 'späteren `user:restore` jeder Beleg für den Rollenverlust.',
        );

        $properties = $detachActivity->properties?->toArray() ?? [];
        $this->assertSame(['editor'], $properties['roles'] ?? null);
    }

    public function testDeletingRoleWithMultipleAssignedUsersLogsOneDetachPerUser(): void
    {
        Role::create(['name' => 'member']);
        $role = Role::create(['name' => 'editor']);
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            $user->assignRole('member');
            $user->assignRole($role);
        }

        Activity::query()->delete();

        $role->deleteOrFail();

        $detachEntries = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $users->first()?->getMorphClass())
            ->get();

        $this->assertCount(3, $detachEntries);

        $subjectIds = $detachEntries->pluck('subject_id')->all();

        foreach ($users as $user) {
            $this->assertContains($user->getKey(), $subjectIds);
        }

        foreach ($detachEntries as $entry) {
            $properties = $entry->properties?->toArray() ?? [];
            $this->assertSame(['editor'], $properties['roles'] ?? null);
        }
    }

    public function testDeletingRoleWithoutAssignedUsersWritesNoDetachActivity(): void
    {
        $role = Role::create(['name' => 'editor']);
        Activity::query()->delete();

        $role->deleteOrFail();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'role')
                ->where('event', 'role_detached')
                ->count(),
        );

        // `role_deleted` aus `RoleLifecycleObserver::deleted()` bleibt
        // erhalten — der Lifecycle-Vorgang an sich ist unabhängig vom
        // Cascade-Pfad und muss weiter dokumentiert werden.
        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'role')
                ->where('event', 'role_deleted')
                ->count(),
        );
    }
}
