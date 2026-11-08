<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SQLite kennt nur die Funktionen, die die App beim Verbindungsaufbau
 * registriert. Registrierung und Abfragen teilen sich den Namen hier, ein
 * Tippfehler fiele sonst erst zur Laufzeit auf.
 */
enum SqliteFunction: string
{
    case UNICODE_LOWER = 'unicode_lower';
}
