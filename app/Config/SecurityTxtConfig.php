<?php

declare(strict_types=1);

namespace App\Config;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use UnexpectedValueException;

final class SecurityTxtConfig
{
    private const string RFC_3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]([01]\d|2[0-3]):[0-5]\d)$/';

    private const string CONTACT_URI = '/^(mailto:|tel:|https:\/\/)\S+$/';

    /**
     * Die drei Schemata decken Mail, Telefon und Webseite ab. Eine Mailadresse
     * ohne `mailto:` oder eine http-Adresse ergäbe eine ungültige Datei
     * (RFC 9116, Abschnitt 2.5.3), und ein Zeilenumbruch schriebe weitere
     * Felder hinein.
     */
    public static function contact(): ?string
    {
        $value = self::nonBlank('security_txt.contact');

        if ($value !== null && preg_match(self::CONTACT_URI, $value) !== 1) {
            throw new UnexpectedValueException(
                'SECURITY_TXT_CONTACT must be a mailto:, tel: or https:// URI: ' . $value,
            );
        }

        return $value;
    }

    /**
     * Nur ein fester Zeitpunkt nach RFC 3339, ohne Sekundenbruchteile: Eine
     * relative Angabe wie „+1 year“ liefe mit jedem Abruf mit, und die Datei
     * veraltete nie. Ein ungültiger Wert wirft, statt eine falsche Datei
     * auszuliefern.
     */
    public static function expires(): ?Carbon
    {
        $value = self::nonBlank('security_txt.expires');

        if ($value === null) {
            return null;
        }

        $expires = preg_match(self::RFC_3339, $value) === 1
            ? Carbon::createFromFormat('Y-m-d\TH:i:sP', $value)
            : null;

        // createFromFormat() rollt unmögliche Werte wie den 30. Februar weiter,
        // statt zu werfen; dann weicht der Rundlauf von der Eingabe ab.
        if ($expires?->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new InvalidFormatException('SECURITY_TXT_EXPIRES is not a valid RFC 3339 date-time: ' . $value);
        }

        return $expires;
    }

    private static function nonBlank(string $key): ?string
    {
        $value = config($key);

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === ''
            ? null
            : $trimmed;
    }
}
