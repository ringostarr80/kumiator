<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

/**
 * Entfernt `null`-Werte aus den WebAuthn-Optionen, bevor sie an den Browser gehen.
 *
 * Der Serializer der webauthn-lib schreibt nicht konfigurierte Felder als `null`
 * mit (etwa `rp.id` oder `authenticatorAttachment`). Die Browser-API
 * `parseCreationOptionsFromJSON` / `parseRequestOptionsFromJSON` macht daraus
 * über WebIDL die Zeichenkette "null" — das führt zu RP-ID-Mismatches und
 * anderen Zeremonie-Fehlern.
 */
final class WebAuthnJsonNormalizer
{
    /**
     * @return array<mixed>
     */
    public static function normalizeOptionsJson(string $json): array
    {
        $decoded = json_decode($json, true);

        $stripped = self::stripNulls(is_array($decoded) ? $decoded : []);

        return is_array($stripped)
            ? $stripped
            : [];
    }

    /**
     * Arbeitet rekursiv, weil die Optionen verschachtelte Objekte enthalten.
     *
     * @param mixed $data Dekodierter JSON-Wert (Array, Skalar oder null)
     * @return mixed Dieselbe Struktur ohne `null`-Werte
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
