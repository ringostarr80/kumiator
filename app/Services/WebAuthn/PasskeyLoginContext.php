<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Services\Concerns\MarksRequestScope;

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
 * Setzer (`PasskeyAuthenticationService`) und Leser (`LogAuthenticationActivityListener`)
 * teilen sich die Instanz über die `scoped()`-Bindung im Container.
 */
final class PasskeyLoginContext
{
    use MarksRequestScope;
}
