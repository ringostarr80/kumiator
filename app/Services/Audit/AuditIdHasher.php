<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Support\Facades\Config;

/**
 * Einweg-Hash für Audit-Ziel-IDs (z. B. `target_id_hash` abgelehnter
 * Autorisierungen). HMAC mit dem APP_KEY aus demselben Grund wie beim
 * `AuditEmailHasher`: niedrig-entropische Schlüssel — etwa fortlaufende
 * Integer-Primärschlüssel — wären als ungesalzener SHA-256 bei einem DB-Leak
 * per Vorabtabelle offline reversibel und instanzübergreifend korrelierbar. Der
 * instanzspezifische Schlüssel begrenzt die Korrelation auf diese Instanz.
 *
 * Nicht-skalare oder fehlende Schlüssel (`null`) liefern `null` — dann gibt es
 * keinen sinnvollen Klartext zu hashen, und die Property bleibt leer.
 */
final class AuditIdHasher
{
    public static function hash(mixed $key): ?string
    {
        if (!is_scalar($key)) {
            return null;
        }

        return hash_hmac('sha256', (string) $key, Config::string('app.key'));
    }
}
