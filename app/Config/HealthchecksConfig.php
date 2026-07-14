<?php

declare(strict_types=1);

namespace App\Config;

use Illuminate\Support\Facades\Config;

/**
 * Typed accessor für `config/healthchecks.php`.
 *
 * `config()`-Aufrufe liefern `mixed` und müssten in Services typed gecastet
 * werden — zentralisiert hier, damit der Pinger frei von Cast-Boilerplate
 * bleibt. Analog zum Muster `App\Config\Vendor\Webauthn\WebauthnConfig`.
 */
final class HealthchecksConfig
{
    private const DEFAULT_BASE_URL = 'https://hc-ping.com';

    public static function baseUrl(): string
    {
        $value = config('healthchecks.base_url', self::DEFAULT_BASE_URL);

        if (!is_string($value)) {
            return self::DEFAULT_BASE_URL;
        }

        $trimmed = rtrim(trim($value), '/');

        return $trimmed === ''
            ? self::DEFAULT_BASE_URL
            : $trimmed;
    }

    /**
     * Liefert den Ping-Key oder `null`, wenn nicht konfiguriert. `null` signalisiert
     * dem Pinger, dass HTTP-Calls übersprungen werden sollen (local/testing).
     */
    public static function pingKey(): ?string
    {
        $value = config('healthchecks.ping_key');

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === ''
            ? null
            : $trimmed;
    }

    public static function timeoutSeconds(): int
    {
        return Config::integer('healthchecks.timeout_seconds');
    }
}
