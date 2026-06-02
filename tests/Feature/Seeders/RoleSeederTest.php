<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Schützt die im `RoleSeeder` festgehaltene Idempotenz-Garantie:
 *  - `composer setup` und `composer deploy` rufen den Seeder bei jedem Deploy
 *    auf. Mehrfaches Ausführen darf weder Duplikate noch Exceptions erzeugen.
 *  - Sobald jemand eine nicht-idempotente Methode in den Seeder einbaut
 *    (z. B. `create()` statt `findOrCreate()`), schlägt dieser Test an.
 *  - Außerdem hält er fest, dass der Seeder `activity-log.view` nur als
 *    Definition anlegt und KEINER Rolle zuweist.
 */
final class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function testRunningSeederTwiceProducesExpectedStateWithoutDuplicates(): void
    {
        $seeder = new RoleSeeder();

        $seeder->run();

        // Spatie cached Roles & Permissions im Container — vor dem zweiten Lauf
        // verwerfen, damit der Seeder die bereits vorhandenen Datensätze
        // tatsächlich aus der DB findet (statt aus stalem Cache).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $seeder->run();

        $this->assertSame(1, Role::query()->where('name', 'member')->count());
        $this->assertSame(1, Role::query()->where('name', 'admin')->count());
        $this->assertSame(1, Permission::query()->where('name', 'activity-log.view')->count());

        // `activity-log.view` wird bewusst KEINER Rolle zugewiesen: das Recht
        // wird pro Person über `activity-log:grant`/`:revoke` vergeben (Least
        // Privilege, Art. 32 DSGVO). Der Seeder hält nur die Definition vor.
        $admin = Role::findByName('admin');
        $this->assertCount(0, $admin->permissions()->where('name', 'activity-log.view')->get());
    }
}
