<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Die Zeile spiegelt die `sessions`-Migration; eine neue Pflichtspalte landet so
 * an einer Stelle statt in jeder Testklasse, die Sitzungen anlegt.
 */
trait InsertsSessions
{
    protected function insertSession(string $id, int $userId, ?string $connection = null): void
    {
        DB::connection($connection)->table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }
}
