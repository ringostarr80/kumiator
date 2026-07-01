<?php

/**
 * Indizes für die Admin-Log-Übersicht (`ActivityLogTable`):
 *  - `(created_at, id)`: Jede Render-Query sortiert `ORDER BY created_at, id`
 *    in gleicher Richtung und die Datumsfilter vergleichen `created_at` als
 *    Range — der zusammengesetzte Index bedient beides ohne Filesort.
 *  - `event`: Der Event-Spaltenfilter ist ein exakter Gleichheitsvergleich.
 *
 * `log_name` und die Morph-Spalten sind bereits in der Create-Migration
 * indiziert; hier fehlen nur diese beiden Zugriffspfade.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_log', static function (Blueprint $table): void {
            $table->index(['created_at', 'id']);
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', static function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'id']);
            $table->dropIndex(['event']);
        });
    }
};
