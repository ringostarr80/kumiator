<?php

/**
 * Hält fest, ab wann ein Konto den Passwort-Login abgeschaltet hat.
 *
 * Ein Zeitstempel statt eines Booleans, weil der Umschaltzeitpunkt forensisch auswertbar
 * bleiben soll: Ein Konto, dessen Passwort-Login kurz vor einem Vorfall wieder aufging, ist
 * erklärungsbedürftig.
 *
 * Bestandszeilen bleiben `null` und behalten den Passwort-Login. Ein Backfill wäre schädlich —
 * er sperrte jeden aus, der noch keinen Passkey registriert hat.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestamp('password_login_disabled_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('password_login_disabled_at');
        });
    }
};
