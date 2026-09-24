<?php

/**
 * Entfernt die ausgestellten API-Tokens, seit die Anwendung keine mehr anbietet.
 *
 * Ohne `HasApiTokens` am User öffnet keine Zeile mehr etwas, aber Name und letzte Nutzung
 * blieben als Daten ohne Zweck liegen — und die Löschpfade der Konten erreichen sie nicht mehr.
 *
 * Die Tabelle selbst bleibt: `auth:sanctum` schlägt jedes mitgeschickte Bearer-Token darin nach,
 * ohne sie bräche jede solche Anfrage mit einem Datenbankfehler ab.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();
    }

    public function down(): void
    {
        // Gelöschte Tokens lassen sich nicht zurückholen.
    }
};
