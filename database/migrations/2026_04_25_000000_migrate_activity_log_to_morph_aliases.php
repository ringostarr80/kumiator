<?php

/**
 * Migriert bestehende Activity-Log-Einträge auf die in `AppServiceProvider`
 * via `Relation::enforceMorphMap()` festgelegten Morph-Aliase.
 *
 * Vor dieser Migration speicherte Spatie\Activitylog die FQCN der polymorphen
 * Modelle (z. B. `App\Models\User`) in den `subject_type`/`causer_type`-Spalten.
 * Mit `enforceMorphMap` schreibt Eloquent ab sofort die kurzen Aliase
 * (`user`, `passkey`). Damit die Lese-Seite konsistent ist und Queries gegen
 * `subject_type = 'user'` auch historische Einträge finden, werden alle
 * bestehenden FQCN-Werte einmalig auf die Aliase aktualisiert.
 *
 * Idempotent: ein erneutes `up()` findet keine FQCN-Werte mehr und ist ein No-Op.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const array MORPH_MAP = [
        'App\\Models\\User' => 'user',
        'App\\Models\\PasskeyCredential' => 'passkey',
    ];

    public function up(): void
    {
        foreach (self::MORPH_MAP as $fqcn => $alias) {
            DB::table('activity_log')
                ->where('subject_type', $fqcn)
                ->update(['subject_type' => $alias]);

            DB::table('activity_log')
                ->where('causer_type', $fqcn)
                ->update(['causer_type' => $alias]);
        }
    }

    public function down(): void
    {
        // Bewusst leer: ein Roll-Back auf FQCN ist nicht zuverlässig möglich.
        // Die Migration ist semantisch ein One-Way-Sweep — die Information
        // „welcher FQCN gehörte zu Schreibzeitpunkt zu welchem Alias" steckt
        // nirgendwo dauerhaft (Klassen können umbenannt, Aliase neu zugeordnet
        // worden sein). Eine symmetrische Rück-Update-Logik würde nur einen
        // bestimmten Snapshot der MORPH_MAP wiederherstellen und wäre damit
        // historisch falsch. Wer wirklich auf Pre-Morph-Map-Zustand muss,
        // braucht ein DB-Backup von vor `up()`.
    }
};
