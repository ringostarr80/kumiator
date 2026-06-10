<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new
/**
 * Spalten für die zweistufige E-Mail-Änderung:
 *  - `pending_email`: Klartext der angefragten neuen Adresse, dorthin geht die Bestätigungs-Mail.
 *  - `pending_email_confirm_token_hash` / `pending_email_cancel_token_hash`: SHA-256-Hex (64 Zeichen)
 *    der beiden in den Mail-URLs transportierten Klartext-Tokens. Je Aktion ein EIGENER Token: der
 *    Cancel-Link geht an die ALTE Adresse — mit einem gemeinsamen Token könnte jeder, der die
 *    Cancel-Mail einsehen kann (Mailbox-Zugriff, Link-Scanner-Logs), statt abzubrechen BESTÄTIGEN.
 *    Klartext wird nie persistiert.
 *  - `pending_email_sent_at`: Zeitstempel des Versands, Grundlage der 60-Min-TTL und des Cleanup-Commands.
 *
 * Unique-Index je Token-Hash-Spalte (Lookup-Pfad). Der Spalten-Wert `pending_email` ist
 * bewusst NICHT unique: zwei User dürfen gleichzeitig dieselbe Adresse pending haben — der Konflikt
 * fällt erst beim Confirm auf, der dann die zweite Anfrage räumt. Würden wir das vorgelagert
 * blockieren, könnte ein Angreifer durch Squatting fremde Email-Wechsel verhindern.
 */
class () extends Migration {
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('pending_email', 255)->nullable()->after('email');
            $table->string('pending_email_confirm_token_hash', 64)->nullable()->after('pending_email');
            $table->string('pending_email_cancel_token_hash', 64)
                ->nullable()
                ->after('pending_email_confirm_token_hash');
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email_cancel_token_hash');
            $table->unique('pending_email_confirm_token_hash');
            $table->unique('pending_email_cancel_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropUnique(['pending_email_confirm_token_hash']);
            $table->dropUnique(['pending_email_cancel_token_hash']);
            $table->dropColumn([
                'pending_email',
                'pending_email_confirm_token_hash',
                'pending_email_cancel_token_hash',
                'pending_email_sent_at',
            ]);
        });
    }
};
