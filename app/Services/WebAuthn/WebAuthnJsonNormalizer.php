<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

/**
 * Strips null values from a decoded WebAuthn options array before sending it
 * to the browser.
 *
 * The PHP webauthn-lib serializer includes optional fields as null when they
 * are not configured (e.g. rp.id, authenticatorAttachment), but the native
 * browser API parseCreationOptionsFromJSON / parseRequestOptionsFromJSON
 * coerces null to the string "null" via WebIDL, causing RP ID mismatches and
 * other ceremony errors.
 */
final class WebAuthnJsonNormalizer
{
    /**
     * Recursively remove null values from a decoded JSON structure.
     *
     * @param mixed $data Decoded JSON value (array, scalar, or null)
     * @return mixed The same structure with all null values removed
     */
    public static function stripNulls(mixed $data): mixed
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
