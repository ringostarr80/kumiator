<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use App\Models\PasskeyCredential;
use App\Models\User;
use Webauthn\PublicKeyCredentialRequestOptions;

interface PasskeyAuthenticationContract
{
    public function createOptions(): PublicKeyCredentialRequestOptions;

    /**
     * Anders als beim Login steht die Identität hier schon fest, deshalb darf
     * `allowCredentials` gefüllt sein: Der Browser bietet dann nur die Passkeys
     * dieses Kontos an, statt den Nutzer aus allen für die Domain gespeicherten
     * wählen zu lassen.
     */
    public function createConfirmationOptions(User $user): PublicKeyCredentialRequestOptions;

    /**
     * @param ?User $identifiedUser Der bereits angemeldete Nutzer im
     *        Bestätigungspfad; ist er gesetzt, muss das Credential ihm gehören.
     *        Im Login bleibt er `null`, weil die Identität dort erst die
     *        Assertion selbst liefert.
     */
    public function verify(
        string $rawResponse,
        PublicKeyCredentialRequestOptions $storedOptions,
        string $host,
        ?User $identifiedUser = null,
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
