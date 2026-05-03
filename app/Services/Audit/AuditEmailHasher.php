<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * DSGVO Art. 5 Abs. 1 lit. c (Datenminimierung): Bei Audit-Einträgen ohne
 * identifizierten Causer kann eine eingegebene E-Mail zu beliebigen Dritten
 * gehören (Tippfehler, Brute-Force gegen fremde Konten) und darf daher nicht
 * im Klartext gespeichert werden. Der Hash erlaubt Korrelation gleicher
 * Versuche, ohne personenbezogene Klartext-Daten abzulegen.
 *
 * `null`/Whitespace liefert `null`, weil sonst alle solchen Eingaben denselben
 * Hash hätten — forensisch wertlos und irreführend.
 */
final class AuditEmailHasher
{
    public static function hash(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($email));

        if ($normalised === '') {
            return null;
        }

        return hash('sha256', $normalised);
    }
}
