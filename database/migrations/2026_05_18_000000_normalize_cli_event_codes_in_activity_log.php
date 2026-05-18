<?php

/**
 * Normalisiert bestehende Activity-Log-Einträge auf das channel-agnostische
 * Event-Code-Schema, das mit dem CLI-Actor-Refactor eingeführt wurde.
 *
 * Vor dem Refactor speicherten CLI-Pfade den Channel direkt im Event-Code
 * (`*_via_cli`-Suffix); seit dem Refactor steckt die Channel-Information
 * ausschließlich im `cli_actor`-Property, und der Event-Code beschreibt
 * nur noch den fachlichen Vorgang. Damit Reports/Filter gegen die neuen
 * Codes auch historische Einträge finden, werden die sechs jemals
 * geschriebenen `_via_cli`-Codes einmalig auf ihre channel-agnostischen
 * Pendants aktualisiert. Die `description`-Spalte bleibt absichtlich
 * unangetastet: Spatie persistiert sie als String beim Insert, die alten
 * Texte sind forensisch reichhaltiger als die neuen neutralen, und ein
 * Überschreiben würde Audit-Information verlieren.
 *
 * Idempotent: ein erneutes `up()` findet keine `_via_cli`-Codes mehr
 * und ist ein No-Op.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const array RENAMES = [
        'user_created_via_cli' => 'user_created',
        'user_approved_via_cli' => 'user_approved',
        'user_deleted_via_cli' => 'user_deleted',
        'user_restored_via_cli' => 'user_restored',
        'password_reset_via_cli' => 'password_reset',
        'email_verified_via_cli' => 'email_verified',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('activity_log')
                ->where('event', $old)
                ->update(['event' => $new]);
        }
    }

    public function down(): void
    {
        // Bewusst leer: die Channel-Information steckt im neuen Schema
        // ausschließlich im `cli_actor`-Property — Pre-Refactor-Einträge
        // hatten dieses Property aber nie. Ein symmetrisches Rück-Update
        // könnte nicht zwischen „war ursprünglich CLI" und „war ursprünglich
        // Web/Seeder" unterscheiden und würde Web-Einträge fälschlich als
        // CLI maskieren. Wer wirklich auf Pre-Refactor-Zustand muss,
        // braucht ein DB-Backup von vor `up()`.
    }
};
