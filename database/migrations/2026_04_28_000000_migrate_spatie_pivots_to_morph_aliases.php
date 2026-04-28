<?php

/**
 * Folge-Migration zu `2026_04_25_000000_migrate_activity_log_to_morph_aliases`.
 *
 * Die Vorgänger-Migration hat ausschließlich `activity_log.subject_type` und
 * `activity_log.causer_type` auf die in `AppServiceProvider` registrierten
 * Morph-Aliase umgestellt — die polymorphen Spalten in den Spatie-Permission-
 * Pivot-Tabellen wurden dabei übersehen. Folge: nach Aktivierung von
 * `Relation::enforceMorphMap()` fragt Spatie\Permission die Pivots mit dem
 * Alias (`'user'`) ab, alte Zeilen halten aber noch den FQCN
 * (`App\\Models\\User`) — Rollen- und Permission-Zuweisungen werden für
 * bestehende User still unsichtbar.
 *
 * Diese Migration heilt das nachträglich für `model_has_roles` und
 * `model_has_permissions`. Idempotent: ein erneuter Lauf findet keine
 * FQCN-Werte mehr und ist ein No-Op.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const array MORPH_MAP = [
        'App\\Models\\User' => 'user',
        'App\\Models\\PasskeyCredential' => 'passkey',
    ];

    private const array TABLES = [
        'model_has_roles',
        'model_has_permissions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            foreach (self::MORPH_MAP as $fqcn => $alias) {
                DB::table($table)
                    ->where('model_type', $fqcn)
                    ->update(['model_type' => $alias]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            foreach (self::MORPH_MAP as $fqcn => $alias) {
                DB::table($table)
                    ->where('model_type', $alias)
                    ->update(['model_type' => $fqcn]);
            }
        }
    }
};
