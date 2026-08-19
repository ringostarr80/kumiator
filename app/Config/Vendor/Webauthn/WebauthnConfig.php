<?php

declare(strict_types=1);

namespace App\Config\Vendor\Webauthn;

use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Getypter Zugriff auf `config/webauthn.php`.
 *
 * Rohe `config()`-Aufrufe quer durch die Service-Schicht liefern `mixed` und
 * verlangen Casts, die PHPStan auf Level max ablehnt. Der gebündelte Zugriff
 * hält die Services frei davon und die Typen explizit.
 */
final class WebauthnConfig
{
    public static function rpName(): string
    {
        $value = config('webauthn.relying_party.name', 'Kumiator');

        return is_string($value)
            ? $value
            : 'Kumiator';
    }

    /**
     * SICHERHEIT: Die RP-ID **muss** die effektive Domain (oder ein registrierbares
     * Suffix davon) sein, unter der die App ausgeliefert wird. Läuft sie etwa auf
     * `app.example.com`, sind `app.example.com` und `example.com` gültig.
     *
     * Die Prüfung von Origin und RP-ID übernimmt die webauthn-lib. Eine falsch
     * gesetzte RP-ID lässt jede Zeremonie scheitern (ungefährlich), eine zu weit
     * gefasste (etwa eine gemeinsame Eltern-Domain) erlaubt dagegen benachbarten
     * Subdomains, Assertions erneut einzuspielen. `WEBAUTHN_RP_ID` in `.env` auf
     * die engste Domain setzen, die alle Origins der App abdeckt. Ohne Wert wird
     * sie aus `APP_URL` abgeleitet.
     */
    public static function rpId(): ?string
    {
        $value = config('webauthn.relying_party.id');

        return is_string($value)
            ? $value
            : null;
    }

    /**
     * Mindestens 1 ms, im Zweifel 60 000 ms.
     *
     * @return positive-int
     */
    public static function timeoutMs(): int
    {
        $value = config('webauthn.timeout', 60_000);

        return is_int($value) && $value >= 1
            ? $value
            : 60_000;
    }

    public static function attestationConveyance(): ?string
    {
        $value = config('webauthn.attestation_conveyance');

        return in_array($value, PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCES, true)
            ? $value
            : PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE;
    }

    public static function userVerification(): string
    {
        $value = config('webauthn.user_verification');

        return in_array($value, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENTS, true)
            ? $value
            : AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED;
    }

    public static function appUrl(): string
    {
        $value = config('app.url', '');

        return is_string($value)
            ? $value
            : '';
    }

    /**
     * Bevorzugt die explizite `relying_party.id` aus der Konfiguration, sonst den
     * Host-Anteil von `app.url`. Fehlt beides, kommt eine leere Zeichenkette
     * zurück — die Bibliothek weist die Assertion dann ab, was der sichere Ausgang ist.
     */
    public static function effectiveHost(): string
    {
        $rpId = self::rpId();

        if ($rpId !== null) {
            return $rpId;
        }

        $host = parse_url(self::appUrl(), PHP_URL_HOST);

        return is_string($host)
            ? $host
            : '';
    }
}
