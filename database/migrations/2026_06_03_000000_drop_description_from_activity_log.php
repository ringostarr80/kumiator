<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Entfernt die `description`-Spalte aus `activity_log`.
 *
 * Der Klartext ist redundant: er wird zur Lesezeit aus dem stabilen `event`-Code
 * abgeleitet ({@see App\Models\Activity::description()} → {@see App\Enums\ActivityEvent::description()}),
 * in der Locale des Betrachters und rückwirkend korrigierbar. Spaties Schreibpfad
 * setzt `description` zwar weiter, der Wrapper verschluckt den Wert aber im
 * Attribut-Setter — es landet also nichts mehr in dieser Spalte.
 *
 * Reversibilität: `down()` legt die Spalte nur nullable wieder an. Die zur
 * Schreibzeit eingefrorenen Original-Strings sind nach `up()` dauerhaft weg;
 * sie waren aber aus `event` herleitbar, ein Backfill ist daher entbehrlich
 * (und würde die Migration unnötig an Enum/Lang-Stände koppeln).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('activity_log', static function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', static function (Blueprint $table): void {
            $table->text('description')->nullable();
        });
    }
};
