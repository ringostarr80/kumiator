<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use App\Models\PasskeyCredential;
use App\Models\User;
use Webauthn\PublicKeyCredentialRequestOptions;

interface PasskeyAuthenticationContract
{
    public function createOptions(): PublicKeyCredentialRequestOptions;

    public function verify(
        string $rawResponse,
        PublicKeyCredentialRequestOptions $storedOptions,
        string $host,
    ): PasskeyCredential;

    /**
     * Schließt die Passkey-Anmeldung ab, indem der Nutzer im Web-Guard
     * eingeloggt wird. Setzt vor dem `Auth::login()` einen Request-Marker
     * (`PasskeyLoginContext`), der den `LogAuthenticationActivityListener`
     * den ausgelösten `Login`-Event ignorieren lässt — der dedizierte
     * Passkey-Activity-Eintrag wird vom Controller nach dem Login geschrieben
     * und würde sonst doppelt gezählt.
     */
    public function loginAuthenticatedUser(User $user): void;
}
