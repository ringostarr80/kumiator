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

    // IPv4-mapped-IPv6-Präfix (`::ffff:0:0/96`) auf Byte-Ebene: 10 Nullbytes + 0xffff.
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public static function truncate(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $ip = self::unwrapMappedIpv4($ip);

        // IpUtils nullt mit den Defaults das letzte IPv4-Oktett (/24) bzw. die
        // letzten 8 IPv6-Byte (/64). Der CIDR-Suffix dokumentiert, dass das Netz
        // (nicht der Host) gespeichert ist — er muss zu den Defaults oben passen.
        $network = IpUtils::anonymize($ip);
        $suffix = str_contains($ip, ':')
            ? self::IPV6_SUFFIX
            : self::IPV4_SUFFIX;

        return $network . $suffix;
    }

    /**
     * Dual-Stack-Proxys melden IPv4-Clients teils als IPv4-mapped-IPv6
     * (`::ffff:a.b.c.d`). Symfony würde die zwar mit IPv4-Semantik anonymisieren
     * (nur das letzte Oktett), der `:`-Suffix-Test unten hinge aber `/64` an und
     * behauptete mehr genullte Bits als real. Darum die Adresse vorab auf ihre
     * dotted-IPv4-Form ziehen — auf Byte-Ebene, damit auch die Hex-Schreibweise
     * (`::ffff:cb00:7107`) erfasst wird, an der Symfonys string-basierte
     * mapped-Erkennung vorbeiläuft.
     */
    private static function unwrapMappedIpv4(string $ip): string
    {
        $packed = inet_pton($ip);

        if ($packed === false || !str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return $ip;
        }

        $dotted = inet_ntop(substr($packed, -4));

        return $dotted === false
            ? $ip
            : $dotted;
    }
}
