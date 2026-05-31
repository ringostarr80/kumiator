<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * DSGVO Art. 5 Abs. 1 lit. c (Datenminimierung): IP-Adressen aus anonymen
 * Fehlversuchen (`login_failed`, `login_locked_out`) gehören potenziell zu
 * beliebigen Dritten und werden mit der globalen Activity-Log-Retention
 * (365 Tage) aufbewahrt. Statt der vollen Adresse wird daher nur das Netz
 * gespeichert — `/24` (IPv4) bzw. `/64` (IPv6). Die Kürzung IST hier der
 * Minimierungs-Hebel, der die lange Retention trägt.
 *
 * `null`/ungültige Eingabe liefert `null` — dann wird gar keine IP-Property
 * geschrieben (z. B. CLI-Auth ohne Request-IP), statt einen wertlosen
 * Platzhalter abzulegen.
 */
final class AuditIpTruncator
{
    private const IPV4_SUFFIX = '/24';
    private const IPV6_SUFFIX = '/64';

    public static function truncate(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // IpUtils nullt mit den Defaults das letzte IPv4-Oktett (/24) bzw. die
        // letzten 8 IPv6-Byte (/64). Der CIDR-Suffix dokumentiert, dass das Netz
        // (nicht der Host) gespeichert ist — er muss zu den Defaults oben passen.
        $network = IpUtils::anonymize($ip);
        $suffix = str_contains($ip, ':')
            ? self::IPV6_SUFFIX
            : self::IPV4_SUFFIX;

        return $network . $suffix;
    }
}
