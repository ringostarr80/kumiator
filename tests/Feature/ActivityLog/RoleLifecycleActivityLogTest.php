<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Validiert die Rollen-Lifecycle-Audits über `RoleLifecycleObserver`.
 *
 * Geprüft wird der zentrale Beobachterpfad (Eloquent-Lifecycle), der für
 * **alle** Aufruf-Quellen identisch greift — direkter Code, Seeder, CLI,
 * künftiges Admin-UI. Der CLI-Pfad bekommt zusätzlich den `cli_actor`
 * über den existierenden `Activity::saving`-Hook angereichert; deshalb
 * ein dedizierter Test mit `WithConsoleEvents`.
 */
final class RoleLifecycleActivityLogTest extends TestCase
{
    use RefreshDatabase;
    // Symfony-Console-Events werden im Test-Modus standardmäßig nicht auf
    // `Illuminate\Console\Events\CommandStarting`/`CommandFinished` gemappt
    // (vgl. `ConsoleActivityLogTest`). Ohne diesen Trait würde der
    // `CaptureConsoleActorListener` für `$this->artisan(...)` nicht feuern
    // und die `cli_actor`-Assertion stillschweigend fehlschlagen.
    use WithConsoleEvents;

    public function testCreatingRoleWritesRoleCreatedActivity(): void
    {
        Activity::query()->delete();

        $role = Role::create(['name' => 'editor']);

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_created')
            ->where('subject_type', $role->getMorphClass())
            ->where('subject_id', $role->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_role_created'), $activity->description);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('editor', $properties['name'] ?? null);
        $this->assertSame('web', $properties['guard_name'] ?? null);
    }

    /**
     * `Role::findOrCreate()` (z. B. aus `RoleSeeder` bei erneutem Deploy)
     * darf für eine bereits existierende Rolle KEIN zusätzliches Audit
     * schreiben — sonst würde jeder Deploy stille Rauschen-Einträge im
     * Log erzeugen.
     */
    public function testFindOrCreateOnExistingRoleDoesNotWriteActivity(): void
    {
        Role::create(['name' => 'editor']);
        Activity::query()->delete();

        Role::findOrCreate('editor');

        $this->assertSame(0, Activity::query()->where('log_name', 'role')->count());
    }

    public function testDeletingRoleWritesRoleDeletedActivityWithPreservedProperties(): void
    {
        $role = Role::create(['name' => 'editor']);
        $roleId = $role->getKey();
        $morphClass = $role->getMorphClass();
        Activity::query()->delete();

        $role->deleteOrFail();

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_deleted')
            ->where('subject_type', $morphClass)
            ->where('subject_id', $roleId)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_role_deleted'), $activity->description);

        // Properties müssen die Rollen-Daten konservieren — `subject_id`
        // zeigt nach DELETE ins Leere, nur diese Properties bleiben
        // forensisch verwertbar.
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('editor', $properties['name'] ?? null);
        $this->assertSame('web', $properties['guard_name'] ?? null);
    }

    public function testRoleCreateCommandWritesActivityWithCliActor(): void
    {
        Activity::query()->delete();

        $command = $this->artisan('role:create');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'editor')
            ->assertSuccessful()
            ->run();

        $role = Role::findByName('editor');

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_created')
            ->where('subject_type', $role->getMorphClass())
            ->where('subject_id', $role->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('cli_actor', $properties);
        $this->assertIsArray($properties['cli_actor']);
        $this->assertSame('role:create', $properties['cli_actor']['command'] ?? null);
    }

    public function testRoleDeleteCommandWritesActivityWithCliActor(): void
    {
        $role = Role::create(['name' => 'editor']);
        $roleId = $role->getKey();
        $morphClass = $role->getMorphClass();
        Activity::query()->delete();

        $command = $this->artisan('role:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.delete_role.ask_name'), 'editor')
            ->expectsConfirmation(__('commands.delete_role.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_deleted')
            ->where('subject_type', $morphClass)
            ->where('subject_id', $roleId)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('editor', $properties['name'] ?? null);
        $this->assertArrayHasKey('cli_actor', $properties);
        $this->assertIsArray($properties['cli_actor']);
        $this->assertSame('role:delete', $properties['cli_actor']['command'] ?? null);
    }
}
