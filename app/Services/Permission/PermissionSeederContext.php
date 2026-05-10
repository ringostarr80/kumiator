<?php

declare(strict_types=1);

namespace App\Services\Permission;

/**
 * Process-scoped Marker, der signalisiert, dass die gerade laufende
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
 * Verwendung im Seeder:
 *   PermissionSeederContext::markActive();
 *   try {
 *       $admin->givePermissionTo($activityLogView);
 *   } finally {
 *       PermissionSeederContext::clear();
 *   }
 *
 * Statisches Design analog zu `PasskeyLoginContext`: ein Seeder-Lauf läuft
 * in einem frischen Prozess, kein Carry-over zwischen Requests. In Tests
 * muss `clear()` zwischen Szenarien aufgerufen werden.
 */
final class PermissionSeederContext
{
    private static bool $active = false;

    public static function markActive(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function clear(): void
    {
        self::$active = false;
    }
}
