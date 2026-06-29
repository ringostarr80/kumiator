<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Namen der im Code definierten Spatie-Permissions.
 *
 * Zentralisiert die zuvor verstreuten Magic-Strings. Definition (`RoleSeeder`),
 * Vergabe/Entzug (`activity-log:grant` / `:revoke`) und Gate-Check samt Audit-`ability`
 * (`ActivityLogTable`) schöpfen so aus einer Quelle — ein Tippfehler oder Rename kann
 * nicht mehr still zwischen `findOrCreate` und Prüfung auseinanderlaufen.
 *
 * Verwendung über `->value`, weil Spatie und das Gate String-Namen erwarten.
 */
enum PermissionName: string
{
    case ACTIVITY_LOG_VIEW = 'activity-log.view';
}
