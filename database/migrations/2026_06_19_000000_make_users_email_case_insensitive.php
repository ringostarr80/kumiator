<?php

/**
 * E-Mail als case-insensitive Identität. SQLite-Default ist BINARY, wodurch
 * `where('email', …)`, der Unique-Index und `Rule::unique` case-sensitiv
 * waren. Fortifys `lowercase_usernames` canonicalisiert nur den Web-Flow;
 * Nicht-Web-Pfade (CLI `user:create`, CLI-/Passkey-Lookups) umgehen das und
 * konnten so Case-Duplikat-Konten anlegen oder einen abweichend
 * geschriebenen User nicht finden. `collate nocase` zieht die
 * Case-Insensitivität auf DB-Ebene — alle Lookups und die Eindeutigkeit
 * greifen ohne App-Code-Änderung.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $this->guardAgainstCaseInsensitiveCollisions();

        Schema::table('users', static function (Blueprint $table): void {
            $table->string('email')->collation('nocase')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('email')->collation('binary')->change();
        });
    }

    /**
     * Der Tabellen-Rebuild würde den neuen NOCASE-Unique-Index andernfalls mit
     * einer rohen Unique-Verletzung quittieren, falls Altdaten zwei nur in der
     * Schreibweise verschiedene Adressen halten. Vorab klar benennen, welche.
     */
    private function guardAgainstCaseInsensitiveCollisions(): void
    {
        $collisions = DB::table('users')
            ->selectRaw('lower(email) as normalized')
            ->groupBy('normalized')
            ->havingRaw('count(*) > 1')
            ->pluck('normalized');

        if ($collisions->isNotEmpty()) {
            throw new RuntimeException(
                'E-Mail-Adressen kollidieren case-insensitiv und müssen vor dieser Migration '
                . 'bereinigt werden: ' . $collisions->implode(', '),
            );
        }
    }
};
