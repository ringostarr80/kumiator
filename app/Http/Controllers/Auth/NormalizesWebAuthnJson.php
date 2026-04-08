<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

/**
 * Provides a helper to strip null values from decoded WebAuthn options JSON
 * before returning them to the browser.
 *
 * The PHP webauthn-lib serializer includes optional fields as null when not
 * configured (e.g. rp.id, authenticatorAttachment), but the native browser
 * API parseCreationOptionsFromJSON / parseRequestOptionsFromJSON coerces null
 * to the string "null" via WebIDL, causing RP ID mismatches and other errors.
 */
trait NormalizesWebAuthnJson
{
    private static function stripNulls(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        return array_filter(
            array_map(static fn (mixed $value): mixed => self::stripNulls($value), $data),
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
