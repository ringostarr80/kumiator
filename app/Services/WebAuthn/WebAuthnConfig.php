<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

/**
 * Typed accessor for the `config/webauthn.php` configuration.
 *
 * Using raw `config()` calls throughout the service layer returns `mixed` and
 * requires casts that PHPStan rejects at level max. Centralising the access
 * here keeps the services clean and the types explicit.
 */
final class WebAuthnConfig
{
    public static function rpName(): string
    {
        $value = config('webauthn.relying_party.name', 'AssociationManager');

        return is_string($value)
            ? $value
            : 'AssociationManager';
    }

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

    public static function attestationConveyance(): string
    {
        $value = config('webauthn.attestation_conveyance', 'none');

        return is_string($value)
            ? $value
            : 'none';
    }

    public static function userVerification(): string
    {
        $value = config('webauthn.user_verification', 'preferred');

        return is_string($value)
            ? $value
            : 'preferred';
    }

    public static function appUrl(): string
    {
        $value = config('app.url', '');

        return is_string($value)
            ? $value
            : '';
    }
}
