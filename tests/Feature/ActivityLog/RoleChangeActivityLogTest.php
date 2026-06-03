<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Listeners\LogRoleChangeListener;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Spatie\Permission\Events\RoleAttachedEvent;
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

    /**
     * Bewusste Festlegung: `syncRoles()` erzeugt auch dann ein detach+attach-Paar,
     * wenn die fachlich gesetzte Rolle identisch zur vorherigen ist. Spatie feuert
     * `RoleDetachedEvent` für alle bisherigen Rollen und `RoleAttachedEvent` für
     * das neue Set — ohne Diff (vendor/spatie/laravel-permission/src/Traits/HasRoles.php
     * syncRoles()). Der Listener spiegelt dieses Verhalten 1:1 ins Activity-Log.
     *
     * Für den einzigen produktiven Call-Site (`role:assign`, Single-Role-Semantik)
     * ist das akzeptables Rauschen im Log. Sobald Multi-Role-Sync eingeführt wird
     * (z. B. über ein UI-Formular mit mehreren gleichzeitig wählbaren Rollen),
     * sollte ein UserRoleService die echten Δadd/Δremove berechnen — dieser Test
     * bricht dann und erzwingt die bewusste Neubewertung.
     */
    public function testSyncingSameRoleLogsDetachAndAttachPair(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Activity::query()->delete();

        $user->syncRoles('admin');

        $detached = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->get();

        $attached = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->get();

        $this->assertCount(1, $detached);
        $this->assertCount(1, $attached);
        $this->assertEqualsCanonicalizing(['admin'], $detached->first()?->properties?->toArray()['roles'] ?? []);
        $this->assertEqualsCanonicalizing(['admin'], $attached->first()?->properties?->toArray()['roles'] ?? []);
    }

    /**
     * Schützt den Early-Return-Pfad in `LogRoleChangeListener::log()`: Wird
     * der Listener mit einer leeren `$rolesOrIds`-Liste aufgerufen, darf
     * **kein** Activity-Log-Eintrag entstehen — sonst hätten wir leere
     * `roles: []`-Einträge ohne fachlichen Wert.
     */
    public function testListenerSkipsLoggingWhenNoRolesProvided(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        (new LogRoleChangeListener())->handleAttached(new RoleAttachedEvent($user, []));

        $this->assertSame(0, Activity::query()->where('log_name', 'role')->count());
    }

    /**
     * Spatie liefert `$rolesOrIds` heterogen — Mischformen aus `RoleContract`-
     * Instanzen und IDs sind dokumentierter Bestandteil des Vertrags. Der
     * Listener muss beide Pfade in `resolveRoleNames()` zusammenführen und
     * im Activity-Log einen einzigen Eintrag mit allen Rollen-Namen erzeugen.
     */
    public function testListenerHandlesMixedRoleObjectsAndIds(): void
    {
        $user = User::factory()->create();
        $admin = Role::findByName('admin');
        $member = Role::findByName('member');
        Activity::query()->delete();

        (new LogRoleChangeListener())->handleAttached(
            new RoleAttachedEvent($user, [$admin, $member->id]),
        );

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertEqualsCanonicalizing(['admin', 'member'], $properties['roles'] ?? []);
    }

    /**
     * Drift-Sichtbarkeit: Wird der Listener mit einer ID aufgerufen, zu der
     * keine Rolle (mehr) existiert, fällt der Name still aus dem Log. Ein
     * `Log::warning` muss das surfacen, damit Operatoren den Vorfall nicht
     * übersehen — der Activity-Log-Eintrag bleibt für die übrigen Rollen
     * trotzdem bestehen.
     */
    public function testListenerLogsWarningForUnknownRoleIds(): void
    {
        $user = User::factory()->create();
        $admin = Role::findByName('admin');
        Activity::query()->delete();

        $handler = new TestHandler();
        Log::swap(new Logger(new MonologLogger('test', [$handler])));

        (new LogRoleChangeListener())->handleAttached(
            new RoleAttachedEvent($user, [$admin->id, 99_999]),
        );

        $this->assertTrue(
            $handler->hasWarningThatContains('LogRoleChangeListener: unknown role IDs received'),
            'Erwartetes Warning für unbekannte Role-IDs wurde nicht ins Log geschrieben.',
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
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertEqualsCanonicalizing(
            ['admin'],
            $activity->properties?->toArray()['roles'] ?? [],
        );
    }

    /**
     * Sichert die Trennung zwischen Maschinen-Code (`event`) und Klartext
     * (`description`): `event` bleibt der stabile Filter-Anker
     * (`role_attached` / `role_detached`), die Description ist die
     * übersetzte Beschreibung für die Activity-Log-UI. Schlägt an, falls
     * jemand die Listener-Logik versehentlich auf den alten Modus
     * zurückdreht (Description == Event).
     */
    public function testActivityDescriptionUsesTranslatedText(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $attached = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($attached);
        $this->assertSame(__('app.activity_role_attached'), $attached->description);
        $this->assertNotSame('role_attached', $attached->description);

        $user->removeRole('admin');

        $detached = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_detached')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($detached);
        $this->assertSame(__('app.activity_role_detached'), $detached->description);
        $this->assertNotSame('role_detached', $detached->description);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('member');
        Role::findOrCreate('admin');
    }
}
