<?php

declare(strict_types=1);

namespace App\Services\Permission;

use App\Services\Concerns\MarksRequestScope;

/**
 * Request-scoped Marker, der signalisiert, dass die gerade laufende
 * Permission-Zuweisung aus einem Seeder stammt und nicht im Activity-Log
 * landen soll.
 *
 * Hintergrund: Spatie's `HasPermissions::givePermissionTo()` feuert
 * `PermissionAttachedEvent` **unbedingt**, auch wenn der interne `attach()`
 * ein No-Op ist (Permission bereits an die Rolle gebunden). Der `RoleSeeder`
 * läuft bei jedem Deploy und ist bewusst idempotent — ohne diesen Marker
 * würde jeder Deploy einen falsch-positiven `permission_attached`-Eintrag
 * produzieren und damit das DSGVO-Audit-Log verdrecken.
 *
 * Setzer (Seeder) und Leser (`LogPermissionChangeListener`) teilen sich die
 * Instanz über die `scoped()`-Bindung im Container.
 */
final class PermissionSeederContext
{
    use MarksRequestScope;
}
