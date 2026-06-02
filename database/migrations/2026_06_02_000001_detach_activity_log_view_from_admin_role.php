<?php

/**
 * Löst die bis dato vom `RoleSeeder` vergebene Bindung `admin → activity-log.view`
 * aus Bestandsdaten.
 *
 * Hintergrund: Das Recht, den Mitglieder-Audit-Trail einzusehen, wird nicht mehr
 * pauschal über die `admin`-Rolle gewährt, sondern pro Person über
 * `activity-log:grant`/`:revoke` (Least Privilege, Art. 32 DSGVO; siehe
 * `RoleSeeder`). Der reine Seeder-Edit entfernt eine bereits in der DB stehende
 * Pivot-Zeile NICHT — `givePermissionTo()` legt nur an. Diese Migration räumt
 * sie auf. Auf einer frischen DB (Permission/Rolle noch nicht geseedet) ist
 * `up()` ein No-Op.
 *
 * Bewusst raw `DB::table()` statt Spatie (`revokePermissionTo()`): konsistent
 * mit den übrigen Activity-Log-Migrationen und ohne `PermissionDetachedEvent`
 * — die strukturelle Deploy-Bereinigung soll keinen `permission_detached`-
 * Audit-Eintrag erzeugen (Causer wäre ohnehin nur der CLI-Migrationslauf).
 *
 * Single-Guard-Annahme (`web`, wie in den Permission-Listenern): Auflösung über
 * den Permission-/Rollen-Namen genügt, weil es projektweit nur einen Guard gibt.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const string PERMISSION = 'activity-log.view';
    private const string ROLE = 'admin';

    public function up(): void
    {
        $permissionId = $this->permissionId();
        $roleId = $this->roleId();

        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->delete();
    }

    public function down(): void
    {
        $permissionId = $this->permissionId();
        $roleId = $this->roleId();

        if ($permissionId === null || $roleId === null) {
            return;
        }

        DB::table('role_has_permissions')->updateOrInsert([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);
    }

    private function permissionId(): ?int
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        return is_numeric($id)
            ? (int) $id
            : null;
    }

    private function roleId(): ?int
    {
        $id = DB::table('roles')->where('name', self::ROLE)->value('id');

        return is_numeric($id)
            ? (int) $id
            : null;
    }
};
