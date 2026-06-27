<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Listeners\LogPermissionChangeListener;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PermissionChangeActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testAttachingPermissionToRoleCreatesActivityLogEntry(): void
    {
        $admin = Role::findByName('admin');
        $permission = Permission::findOrCreate('test.permission');
        Activity::query()->delete();

        $admin->givePermissionTo($permission);

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_type', $admin->getMorphClass())
            ->where('subject_id', $admin->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('permissions', $properties);
        $this->assertIsArray($properties['permissions']);
        $this->assertContains('test.permission', $properties['permissions']);
    }

    public function testAttachingPermissionDirectlyToUserCreatesActivityLogEntry(): void
    {
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('test.permission');
        Activity::query()->delete();

        $user->givePermissionTo($permission);

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('permissions', $properties);
        $this->assertIsArray($properties['permissions']);
        $this->assertContains('test.permission', $properties['permissions']);
    }

    public function testRevokingPermissionFromRoleCreatesActivityLogEntry(): void
    {
        $admin = Role::findByName('admin');
        $permission = Permission::findOrCreate('test.permission');
        $admin->givePermissionTo($permission);
        Activity::query()->delete();

        $admin->revokePermissionTo($permission);

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_detached')
            ->where('subject_type', $admin->getMorphClass())
            ->where('subject_id', $admin->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('permissions', $properties);
        $this->assertIsArray($properties['permissions']);
        $this->assertContains('test.permission', $properties['permissions']);
    }

    /**
     * Spatie Permission feuert `PermissionAttachedEvent` genau einmal pro
     * `givePermissionTo()`-Aufruf, mit allen Permissions im Payload. Der
     * Listener fasst das zu **einem** Activity-Log-Eintrag mit allen
     * Permission-Namen zusammen — doppelte Einträge wären ein Hinweis auf
     * doppelte Listener-Registrierung (Auto-Discovery + explizites
     * `Event::listen()`).
     */
    public function testAttachingMultiplePermissionsAtOnceLogsSingleEntryWithAllNames(): void
    {
        $admin = Role::findByName('admin');
        $first = Permission::findOrCreate('test.first');
        $second = Permission::findOrCreate('test.second');
        Activity::query()->delete();

        $admin->givePermissionTo($first, $second);

        $activities = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_type', $admin->getMorphClass())
            ->where('subject_id', $admin->getKey())
            ->get();

        $this->assertCount(1, $activities);
        $properties = $activities->first()?->properties?->toArray() ?? [];
        $this->assertEqualsCanonicalizing(
            ['test.first', 'test.second'],
            $properties['permissions'] ?? [],
        );
    }

    public function testAttachingPermissionAsAuthenticatedActorRecordsCauser(): void
    {
        $actor = User::factory()->create();
        $admin = Role::findByName('admin');
        $permission = Permission::findOrCreate('test.permission');
        Activity::query()->delete();

        $this->actingAs($actor);
        $admin->givePermissionTo($permission);

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_type', $admin->getMorphClass())
            ->where('subject_id', $admin->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->getMorphClass(), $activity->causer_type);
        $this->assertSame($actor->getKey(), $activity->causer_id);
    }

    /**
     * Bewusst festgeschriebenes Spatie-Verhalten: `syncPermissions()` macht
     * intern `permissions()->detach()` **ohne Event** und ruft danach
     * `givePermissionTo($permissions)` (das nur das Attach-Event feuert).
     * Entzüge über `syncPermissions()` werden also nicht geloggt — heute
     * irrelevant (keine Call-Site), aber bei Einführung eines Admin-UIs
     * bricht dieser Test bewusst und zwingt zur Neubewertung (z. B. via
     * `PermissionSyncService` mit explizitem Δ-Logging).
     */
    public function testSyncPermissionsOnlyLogsAttachAndDocumentsSpatieGap(): void
    {
        $admin = Role::findByName('admin');
        $oldPermission = Permission::findOrCreate('test.old');
        $newPermission = Permission::findOrCreate('test.new');
        $admin->givePermissionTo($oldPermission);
        Activity::query()->delete();

        $admin->syncPermissions([$newPermission]);

        $detached = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_detached')
            ->where('subject_id', $admin->getKey())
            ->get();

        $attached = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_id', $admin->getKey())
            ->get();

        $this->assertCount(
            0,
            $detached,
            'Spatie sollte hier KEIN PermissionDetachedEvent feuern — wenn das jetzt eines tut, '
            . 'wurde der Quirk in einer neuen Spatie-Version repariert und dieser Test sowie '
            . 'der DocBlock im Listener brauchen ein Update.',
        );
        $this->assertCount(1, $attached);
        $this->assertEqualsCanonicalizing(
            ['test.new'],
            $attached->first()?->properties?->toArray()['permissions'] ?? [],
        );
    }

    /**
     * Schützt den Early-Return-Pfad in `LogPermissionChangeListener::log()`:
     * Wird der Listener mit einer leeren `$permissionsOrIds`-Liste
     * aufgerufen, darf **kein** Activity-Log-Eintrag entstehen — sonst
     * hätten wir leere `permissions: []`-Einträge ohne fachlichen Wert.
     */
    public function testListenerSkipsLoggingWhenNoPermissionsProvided(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        (new LogPermissionChangeListener())->handleAttached(new PermissionAttachedEvent($user, []));

        $this->assertSame(0, Activity::query()->where('log_name', 'permission')->count());
    }

    /**
     * Spatie liefert `$permissionsOrIds` heterogen — Mischformen aus
     * `PermissionContract`-Instanzen und IDs sind dokumentierter Bestandteil
     * des Vertrags. Der Listener muss beide Pfade in
     * `resolvePermissionNames()` zusammenführen und im Activity-Log einen
     * einzigen Eintrag mit allen Permission-Namen erzeugen.
     */
    public function testListenerHandlesMixedPermissionObjectsAndIds(): void
    {
        $user = User::factory()->create();
        $first = Permission::findOrCreate('test.first');
        $second = Permission::findOrCreate('test.second');
        Activity::query()->delete();

        (new LogPermissionChangeListener())->handleAttached(
            new PermissionAttachedEvent($user, [$first, $second->id]),
        );

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertEqualsCanonicalizing(
            ['test.first', 'test.second'],
            $activity->properties?->toArray()['permissions'] ?? [],
        );
    }

    /**
     * Drift-Sichtbarkeit: Wird der Listener mit einer ID aufgerufen, zu
     * der keine Permission (mehr) existiert, fällt der Name still aus dem
     * Log. Ein `Log::warning` muss das surfacen, damit Operatoren den
     * Vorfall nicht übersehen — der Activity-Log-Eintrag bleibt für die
     * übrigen Permissions trotzdem bestehen.
     */
    public function testListenerLogsWarningForUnknownPermissionIds(): void
    {
        $user = User::factory()->create();
        $permission = Permission::findOrCreate('test.permission');
        Activity::query()->delete();

        $handler = new TestHandler();
        Log::swap(new Logger(new MonologLogger('test', [$handler])));

        (new LogPermissionChangeListener())->handleAttached(
            new PermissionAttachedEvent($user, [$permission->id, 99_999]),
        );

        $this->assertTrue(
            $handler->hasWarningThatContains('LogPermissionChangeListener: unknown permission IDs received'),
            'Erwartetes Warning für unbekannte Permission-IDs wurde nicht ins Log geschrieben.',
        );

        $warningRecord = null;

        foreach ($handler->getRecords() as $record) {
            if ($record['level'] === Level::Warning->value) {
                $warningRecord = $record;
                break;
            }
        }

        $this->assertNotNull($warningRecord);
        $context = $warningRecord['context'] ?? [];
        $this->assertIsArray($context);
        $this->assertSame([99_999], $context['missing_ids'] ?? null);

        $activity = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertEqualsCanonicalizing(
            ['test.permission'],
            $activity->properties?->toArray()['permissions'] ?? [],
        );
    }

    /**
     * Sichert die Trennung zwischen Maschinen-Code (`event`) und Klartext
     * (`description`): `event` bleibt der stabile Filter-Anker
     * (`permission_attached` / `permission_detached`), die Description ist
     * die übersetzte Beschreibung für die Activity-Log-UI.
     */
    public function testActivityDescriptionUsesTranslatedText(): void
    {
        $admin = Role::findByName('admin');
        $permission = Permission::findOrCreate('test.permission');
        Activity::query()->delete();

        $admin->givePermissionTo($permission);

        $attached = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_attached')
            ->where('subject_id', $admin->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($attached);
        $this->assertSame(__('app.activity_permission_attached'), $attached->description);
        $this->assertNotSame('permission_attached', $attached->description);

        $admin->revokePermissionTo($permission);

        $detached = Activity::query()
            ->where('log_name', 'permission')
            ->where('event', 'permission_detached')
            ->where('subject_id', $admin->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($detached);
        $this->assertSame(__('app.activity_permission_detached'), $detached->description);
        $this->assertNotSame('permission_detached', $detached->description);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('member');
        Role::findOrCreate('admin');
    }
}
