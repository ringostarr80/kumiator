<?php

/**
 * Der WebAuthn-User-Handle wandert bei der Registrierung auf den Authenticator und wird bei
 * synchronisierten Passkeys zum Cloud-Dienst des Anbieters mitgenommen. Der bisher verwendete
 * Primärschlüssel verrät dort Registrierungsreihenfolge und Größenordnung der Nutzerbasis —
 * bei einer Instanz pro Verein eine Aussage über den Verein selbst.
 *
 * 43 Zeichen sind Base64URL ohne Padding aus 32 Zufallsbytes und bleiben damit unter den
 * 64 Bytes, die die WebAuthn-Spezifikation für `user.id` zulässt.
 *
 * Bereits registrierte Credentials behalten ihren alten Handle: Die Zeremonie vergleicht die
 * Assertion gegen den Wert im gespeicherten CredentialRecord, nicht gegen diese Spalte. Ein
 * Backfill der Records wäre deshalb nicht nur überflüssig, sondern bräche die Passkeys.
 *
 * Einen Wert brauchen die Bestandszeilen trotzdem, denn er greift ab der nächsten Registrierung:
 * Wer schon einen Passkey hat, trägt danach zwei verschiedene Handles nebeneinander. Für die
 * Anmeldung ist das folgenlos, weil jeder Record gegen seinen eigenen geprüft wird.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ParagonIE\ConstantTime\Base64UrlSafe;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('webauthn_user_handle', 43)->nullable()->after('password');
        });

        // Jede Zeile braucht einen eigenen Zufallswert, also ein Statement je Nutzer.
        // Der Migrator wrappt eine Migration nur bei Treibern mit transaktionalem DDL;
        // ohne diese Transaktion wäre sonst jedes dieser Statements ein eigener Commit.
        DB::transaction(static function (): void {
            foreach (DB::table('users')->pluck('id') as $id) {
                DB::table('users')->where('id', $id)->update([
                    'webauthn_user_handle' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
                ]);
            }
        });

        Schema::table('users', static function (Blueprint $table): void {
            $table->string('webauthn_user_handle', 43)->nullable(false)->change();
        });

        Schema::table('users', static function (Blueprint $table): void {
            $table->unique('webauthn_user_handle');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropUnique(['webauthn_user_handle']);
            $table->dropColumn('webauthn_user_handle');
        });
    }
};
