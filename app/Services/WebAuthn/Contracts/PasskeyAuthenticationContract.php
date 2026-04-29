<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use App\Models\User;
use Webauthn\PublicKeyCredentialRequestOptions;

interface PasskeyAuthenticationContract
{
    public function createOptions(?User $user = null): PublicKeyCredentialRequestOptions;

    public function verify(string $rawResponse, PublicKeyCredentialRequestOptions $storedOptions, string $host): User;

    /**
     * Schließt die Passkey-Anmeldung ab, indem der Nutzer im Web-Guard
     * eingeloggt wird. Setzt vor dem `Auth::login()` einen Request-Marker
     * (`PasskeyLoginContext`), der den `LogAuthenticationActivityListener`
     * den ausgelösten `Login`-Event ignorieren lässt — der dedizierte
     * Passkey-Activity-Eintrag stammt aus `verify()` und würde sonst
     * doppelt gezählt.
     */
    public function loginAuthenticatedUser(User $user): void;

    /**
     * Run a fake credential DB lookup to equalise response time between known
     * and unknown e-mail addresses, preventing timing-based e-mail enumeration
     * on the options endpoint.
     */
    public function runFakeCredentialLookup(): void;
}
