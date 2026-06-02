<?php

/**
 * Hängt bestehende anonyme Dritt-Forensik-Einträge vom `auth`- in den neuen
 * `forensic`-Kanal um, damit die verkürzte Retention (Art. 5(1)(e) DSGVO,
 * siehe `config/activitylog.php` + `routes/console.php`) auch Bestands-Zeilen
 * erfasst. Betroffen sind genau die Event-Codes, die der
 * `LogAuthenticationActivityListener` ab jetzt in den `forensic`-Kanal schreibt.
 *
 * Idempotent: der `log_name = 'auth'`-Guard macht ein erneutes `up()` zum
 * No-Op. Reversibel, weil die Zuordnung Event → Kanal deterministisch ist.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    private const array FORENSIC_EVENTS = [
        'login_failed',
        'login_locked_out',
        'password_reset_requested',
        'passkey_login_failed',
    ];

    public function up(): void
    {
        DB::table('activity_log')
            ->whereIn('event', self::FORENSIC_EVENTS)
            ->where('log_name', 'auth')
            ->update(['log_name' => 'forensic']);
    }

    public function down(): void
    {
        DB::table('activity_log')
            ->whereIn('event', self::FORENSIC_EVENTS)
            ->where('log_name', 'forensic')
            ->update(['log_name' => 'auth']);
    }
};
