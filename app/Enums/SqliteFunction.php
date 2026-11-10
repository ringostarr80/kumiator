<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Funktionen hier kennt SQLite nur, weil die App sie beim
 * Verbindungsaufbau registriert. Registrierung und Abfragen teilen sich den
 * Namen hier, ein Tippfehler fiele sonst erst zur Laufzeit auf.
 */
enum SqliteFunction: string
{
    case UNICODE_LOWER = 'unicode_lower';
}
