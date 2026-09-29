<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Forensische Properties für anonyme Anfragen: gekürzte IP (Netz statt Host —
 * DSGVO-Datenminimierung) und der auf 255 Zeichen begrenzte User-Agent,
 * jeweils nur wenn vorhanden. Der User-Agent ist auf den anonymen Pfaden
 * angreiferkontrolliert; der Längen-Cap verhindert, dass beliebig lange Header
 * die langlebig aufbewahrte Forensik-Tabelle aufblähen. Ohne Request-IP (z. B.
 * CLI-Auth) bleibt das Array leer.
 */
final class AuditForensicProperties
{
    /**
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        $properties = [];

        $ip = AuditIpTruncator::truncate($request->ip());

        if ($ip !== null) {
            $properties['ip'] = $ip;
        }

        $userAgent = $request->userAgent();

        if ($userAgent !== null) {
            // Ungültiges UTF-8 im angreiferkontrollierten Header verwerfen (die
            // Gleich-Charset-Konvertierung ersetzt Malformed-Bytes): Spaties
            // `collection`-Cast serialisiert die Properties per `json_encode`,
            // das an solchen Bytes mit einer `JsonEncodingException` bräche und
            // den synchronen Forensik-Insert sprengte (HTTP 500, verlorener
            // Audit-Eintrag).
            $userAgent = mb_convert_encoding($userAgent, 'UTF-8', 'UTF-8');
            $properties['user_agent'] = Str::limit($userAgent, 255, '');
        }

        return $properties;
    }
}
