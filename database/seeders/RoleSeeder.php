<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Source-of-Truth für die im Code definierten Rollen und Permissions.
 *
 * Dieser Seeder läuft bei jedem Deploy (siehe `composer setup` und
 * `composer deploy`) und ist bewusst idempotent: `Role::findOrCreate()` und
 * `Permission::findOrCreate()` legen fehlende Datensätze an und sind ein No-Op,
 * wenn sie bereits existieren.
 *
 * **Permissions werden hier NICHT an Rollen gebunden.** Insbesondere
 * PermissionName::ACTIVITY_LOG_VIEW->value wird bewusst keiner Rolle (auch nicht `admin`) zugewiesen:
 * Das Recht, den Mitglieder-Audit-Trail einzusehen, ist eine sicherheits- und
 * datenschutzsensible Berechtigung (Least Privilege / Need-to-know, Art. 32
 * DSGVO). Sie wird pro Person über `activity-log:grant` vergeben und über
 * `activity-log:revoke` wieder entzogen (jeweils auditiert über den
 * `LogPermissionChangeListener`). Der Seeder hält nur die **Definition** der
 * Permission vor, damit sie reproduzierbar existiert und die Grant-Commands
 * sie referenzieren können.
 *
 * Konsequenz: Manuell pro User vergebene Grants werden vom Seeder weder
 * angelegt noch wieder entfernt — anders als bei Rollen-Permission-Bindungen
 * gibt es hier also kein „Heilen" oder „Überschreiben" durch einen Deploy.
 */
class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::findOrCreate('member');
        Role::findOrCreate('admin');

        Permission::findOrCreate(PermissionName::ACTIVITY_LOG_VIEW->value);
    }
}
