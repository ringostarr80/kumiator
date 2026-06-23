<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Anzeige-/Eingabe-Zeitzone der App (deutscher Markt).
 *
 * `app.timezone` bleibt für Speicherung und Carbon-Default bewusst UTC; in
 * dieser Zone stellt die App Zeitstempel dar und interpretiert Datums-Eingaben
 * (z. B. die Tagesgrenzen der Activity-Log-Filter). Einzige Quelle, statt den
 * Zonen-String mehrfach hartzukodieren (CLAUDE.md: Enums statt Magic-Strings).
 */
enum AppTimezone: string
{
    case DISPLAY = 'Europe/Berlin';
}
