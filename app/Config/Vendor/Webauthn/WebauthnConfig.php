<?php

declare(strict_types=1);

namespace App\Config\Vendor\Webauthn;

use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Typed accessor for the `config/webauthn.php` configuration.
 *
 * Using raw `config()` calls throughout the service layer returns `mixed` and
 * requires casts that PHPStan rejects at level max. Centralising the access
 * here keeps the services clean and the types explicit.
 */
final class WebauthnConfig
{
    public static function rpName(): string
    {
        $value = config('webauthn.relying_party.name', 'AssociationManager');

        return is_string($value)
            ? $value
            : 'AssociationManager';
    }

    /**
     * Returns the explicitly configured RP ID (effective domain), or null when unset.
     *
     * SECURITY: The RP ID **must** be the effective domain (or a registrable
     * suffix of it) from which the app is served. For example, if the app runs
     * on `app.example.com`, valid RP IDs are `app.example.com` or `example.com`.
     *
     * Origin / RP ID validation is delegated to webauthn-lib. A misconfigured
     * RP ID will cause all ceremonies to fail (safe), but an overly broad RP ID
     * (e.g. a shared parent domain) could allow sibling subdomains to replay
     * assertions. Set `WEBAUTHN_RP_ID` in `.env` to the narrowest domain that
     * covers all app origins. Leave unset to auto-derive from `APP_URL` via
     * {@see self::effectiveHost()}.
     */
    public static function rpId(): ?string
    {
        $value = config('webauthn.relying_party.id');

        return is_string($value)
            ? $value
            : null;
    }

    /**
     * Returns the timeout in milliseconds (minimum 1 ms, default 60 000 ms).
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
     * Returns the effective RP host used for WebAuthn validator calls.
     *
     * Prefers the explicit `relying_party.id` from config. Falls back to the
     * host component of `app.url`. Returns an empty string when neither is set,
     * which will cause the library to reject the assertion – a safe failure.
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
