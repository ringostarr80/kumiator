<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

/**
 * Request-scoped Marker, der signalisiert, dass das gerade laufende
 * `Auth::login()` aus dem Passkey-Pfad stammt.
 *
 * Hintergrund: Sowohl Passkey- als auch Passwort-Anmeldung lösen Laravels
 * `Illuminate\Auth\Events\Login`-Event aus. Der `LogAuthenticationActivityListener`
 * schreibt für dieses Event einen `password_login_succeeded`-Activity-Eintrag.
 * Beim Passkey-Pfad existiert aber bereits ein dedizierter Eintrag aus
 * `PasskeyCredential::recordSuccessfulLoginActivity()` — ohne diesen Marker
 * würde jede Passkey-Anmeldung doppelt geloggt (einmal als Passkey, einmal
 * als Passwort).
 *
 * Verwendung im Passkey-Controller:
 *   PasskeyLoginContext::markActive();
 *   try {
 *       Auth::login($user);
 *   } finally {
 *       PasskeyLoginContext::clear();
 *   }
 *
 * Statisches Design ist hier vertretbar, weil PHP-Requests einen frischen
 * Prozess-Zustand haben (kein Carry-over zwischen Requests). In Tests muss
 * `clear()` zwischen Szenarien aufgerufen werden — der TestCase tut das
 * automatisch im `setUp()`.
 */
final class PasskeyLoginContext
{
    private static bool $active = false;

    public static function markActive(): void
    {
        self::$active = true;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function clear(): void
    {
        self::$active = false;
    }
}
