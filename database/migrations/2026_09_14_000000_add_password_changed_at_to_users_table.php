<?php

/**
 * Hält fest, wann das Passwort zuletzt gesetzt wurde.
 *
 * Gebraucht wird der Vergleich mit `password_login_disabled_at`: Der Verdacht, der hinter
 * dem abgeschalteten Passwort-Login steht, gilt dem Passwort von damals — nicht einem, das
 * seither per Passkey-bestätigter Sitzung oder auf der Konsole gesetzt wurde.
 *
 * Bestandszeilen bleiben `null` und zählen als „älter als jede Abschaltung": Ihr Passwort
 * wird beim Wiedereinschalten verworfen, wie bisher. Ein Backfill aus `updated_at` behauptete
 * eine Kenntnis, die es nicht gibt.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestamp('password_changed_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
