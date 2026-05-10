<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Permission\PermissionSeederContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Source-of-Truth für Rollen und ihre Permission-Zuweisungen.
 *
 * Dieser Seeder läuft bei jedem Deploy (siehe `composer setup` und
 * `composer deploy`) und ist bewusst idempotent:
 *  - `Role::findOrCreate()` und `Permission::findOrCreate()` legen fehlende
 *    Datensätze an und sind ein No-Op, wenn sie bereits existieren.
 *  - `givePermissionTo()` nutzt intern `syncWithoutDetaching()` und ergänzt
 *    fehlende Pivot-Einträge ohne Duplikate.
 *
 * Wichtige Semantik: Diese Idempotenz schließt das **Heilen** manueller
 * Eingriffe ein. Wer einer Rolle zur Laufzeit (z. B. via Tinker) eine im
 * Code definierte Permission entzieht, bekommt sie beim nächsten Deploy
 * automatisch zurück. Das ist beabsichtigt, solange Permissions ausschließlich
 * im Code definiert werden und es kein Admin-UI für Permission-Verwaltung gibt.
 *
 * Sobald ein Admin-UI eingeführt wird, das Rollen/Permissions zur Laufzeit
 * verändern kann, muss diese Strategie überdacht werden — sonst überschreibt
 * jeder Deploy stillschweigend die per UI vorgenommenen Anpassungen.
 */
class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::findOrCreate('member');

        $activityLogView = Permission::findOrCreate('activity-log.view');

        $admin = Role::findOrCreate('admin');

        // Spatie's `givePermissionTo()` feuert `PermissionAttachedEvent`
        // **unbedingt** — auch wenn der interne Attach ein No-Op ist
        // (Permission war bereits an die Rolle gebunden). Ohne diesen
        // Marker würde jeder Deploy einen falsch-positiven
        // `permission_attached`-Eintrag im Activity-Log erzeugen.
        PermissionSeederContext::markActive();

        try {
            $admin->givePermissionTo($activityLogView);
        } finally {
            PermissionSeederContext::clear();
        }
    }
}
