<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * DSGVO Art. 5 Abs. 1 lit. c (Datenminimierung): Bei Audit-Einträgen ohne
 * identifizierten Causer kann eine eingegebene E-Mail zu beliebigen Dritten
 * gehören (Tippfehler, Brute-Force gegen fremde Konten) und darf daher nicht
 * im Klartext gespeichert werden. Der Hash erlaubt Korrelation gleicher
 * Versuche, ohne personenbezogene Klartext-Daten abzulegen.
 *
 * HMAC mit einem Schlüssel aus dem APP_KEY statt purem SHA-256:
 * E-Mail-Adressen sind niedrig-entropisch — ungesalzene Hashes wären bei
 * einem DB-Leak per Wörterbuch offline reversibel und systemübergreifend
 * korrelierbar. Der instanzspezifische Schlüssel (jeder Verein hat seine
 * eigene Instanz) begrenzt die Korrelation auf diese Instanz; eine
 * APP_KEY-Rotation bricht die Korrelation zu älteren Einträgen.
 *
 * Abgeleitet statt direkt: Laravel signiert URLs per HMAC-SHA256 mit dem
 * APP_KEY selbst. Mit demselben Schlüssel wäre der Hash einer als E-Mail
 * eingeschickten URL ohne Großbuchstaben deren gültige Signatur, und wer den
 * Audit-Trail liest, könnte im Namen der App signieren.
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

        $normalised = User::normalizeEmail($email);

        if ($normalised === '') {
            return null;
        }

        $secret = hash_hkdf('sha256', Config::string('app.key'), info: 'audit-email-hash');

        return hash_hmac('sha256', $normalised, $secret);
    }
}
