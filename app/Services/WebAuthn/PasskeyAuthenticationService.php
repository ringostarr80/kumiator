<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use Illuminate\Support\Facades\Auth;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Orchestriert die WebAuthn-Anmeldezeremonie (Assertion).
 *
 * Ablauf:
 *   1. `createOptions()` liefert die PublicKeyCredentialRequestOptions. Als JSON
 *      an den Browser schicken und in der Session ablegen.
 *   2. Der Browser ruft `navigator.credentials.get()` auf und postet das Ergebnis.
 *   3. `verify()` mit dem rohen JSON, den abgelegten Optionen und dem effektiven
 *      Host aufrufen. Bei Erfolg kommt die passende PasskeyCredential zurück.
 */
final class PasskeyAuthenticationService implements PasskeyAuthenticationContract
{
    /**
     * Öffentlicher ES256-Schlüssel (COSE/CBOR, hex) für die Fake-Verifikation.
     *
     * Muss ein gültiger Kurvenpunkt sein: Einen unlesbaren Schlüssel verwirft die
     * Zeremonie vor der Signaturprüfung, und der eingesparte Rechenweg wäre genau
     * das Zeitsignal, das die Fake-Verifikation verwischen soll. Der zugehörige
     * private Schlüssel wurde verworfen — geprüft wird hier nie erfolgreich.
     */
    private const string FAKE_COSE_PUBLIC_KEY_HEX = 'a5010203262001215820bf7616acd433fac06857842cf5cbc188'
        . '381154d24a2eb29c80b1f814266f8cf32258200ad5a5e562ed39a217cd1ad9715aa4b45ab40e926ce23bfc55eacf4a'
        . '4565b0f2';

    public function __construct(
        private readonly WebAuthnValidatorFactoryContract $validatorFactory,
        private readonly PasskeyCredentialRepositoryContract $repository,
        private readonly SerializerInterface $serializer,
        private readonly PasskeyLoginContext $loginContext,
    ) {
    }

    /**
     * `allowCredentials` bleibt leer: Eine nutzerbezogene Liste würde einem
     * unauthentifizierten Aufrufer verraten, ob zu einer E-Mail-Adresse ein
     * Passkey existiert, und deren Credential-IDs offenlegen. Der Browser
     * wählt stattdessen selbst aus den discoverable Passkeys der RP.
     */
    public function createOptions(): PublicKeyCredentialRequestOptions
    {
        $timeout = WebauthnConfig::timeoutMs();

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: WebauthnConfig::rpId(),
            userVerification: WebauthnConfig::userVerification(),
            timeout: $timeout,
        );
    }

    /**
     * Der Audit-Eintrag für den Erfolg und das Freischaltungs-Gate sind Sache des
     * Aufrufers, und zwar NACH abgeschlossenem Login — so erzeugt ein nicht
     * freigeschalteter Nutzer nie einen `passkey_login_succeeded`-Eintrag.
     *
     * @param string $rawResponse JSON-String, wie ihn der Browser schickt
     * @param PublicKeyCredentialRequestOptions $storedOptions Die Optionen, die
     *        beim Aufruf von createOptions() in der Session abgelegt wurden
     * @param string $host Die effektive Domain (z. B. "localhost")
     * @throws AuthenticatorResponseVerificationException bei Client-Fehlern
     */
    public function verify(
        string $rawResponse,
        PublicKeyCredentialRequestOptions $storedOptions,
        string $host,
    ): PasskeyCredential {
        $publicKeyCredential = $this->serializer->deserialize($rawResponse, PublicKeyCredential::class, 'json');

        $response = $publicKeyCredential->response;

        if (!($response instanceof AuthenticatorAssertionResponse)) {
            throw new AuthenticatorResponseVerificationException('invalid_response_type');
        }

        // Credential über die ID auflösen (in der Browser-Antwort Base64URL-codiert)
        $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
        $passkeyModel = $this->repository->findByCredentialId($credentialId);

        $credentialRecord = $passkeyModel !== null
            ? $this->deserializeCredentialSource($passkeyModel)
            : null;

        if ($passkeyModel === null || $credentialRecord === null) {
            $this->runFakeVerification($response, $storedOptions, $host);
            throw new AuthenticatorResponseVerificationException('credential_not_found');
        }

        $validator = $this->validatorFactory->buildAssertionValidator(WebauthnConfig::appUrl());

        // Ohne `allowCredentials` verlangt die Spezifikation, dass der Authenticator
        // den User-Handle mitliefert; `CheckUserHandle` prüft ihn gegen den
        // gespeicherten. Den gespeicherten selbst einzusetzen hieße, gegen sich
        // selbst zu vergleichen — die Prüfung wäre wirkungslos.
        $updatedRecord = $validator->check($credentialRecord, $response, $storedOptions, $host, $response->userHandle);

        // Aktualisierten Counter und die Backup-Flags festschreiben
        $this->repository->updateAfterAuthentication(
            $passkeyModel,
            $this->serializer->serialize($updatedRecord, 'json'),
            $updatedRecord->counter,
            $updatedRecord->backupStatus ?? false,
        );

        return $passkeyModel;
    }

    /**
     * Schließt die Passkey-Anmeldung ab: Login im Web-Guard, gewrappt vom
     * `PasskeyLoginContext`-Marker (`markActive()`/`clear()`). Der Marker signalisiert
     * dem `LogAuthenticationActivityListener::handleLogin()`, dass der gerade
     * laufende `Login`-Event aus dem Passkey-Pfad stammt — der dedizierte
     * Activity-Eintrag des Controllers (über `recordSuccessfulLoginActivity()`)
     * würde sonst von einem zusätzlichen `password_login_succeeded`-Eintrag
     * begleitet.
     *
     * `try/finally` stellt sicher, dass der Marker auch dann zurückgesetzt
     * wird, wenn `Auth::login()` eine Exception wirft (z. B. SessionGuard-
     * interner Fehler) — sonst würde der nächste echte Passwort-Login im
     * selben Request still verschluckt.
     */
    public function loginAuthenticatedUser(User $user): void
    {
        $this->loginContext->markActive();

        try {
            Auth::login($user);
        } finally {
            $this->loginContext->clear();
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ──────────────────────────────────────────────────────────────────────────

    private function deserializeCredentialSource(PasskeyCredential $model): CredentialRecord
    {
        $json = $this->repository->getSerializedCredentialSource($model);

        return $this->serializer->deserialize($json, CredentialRecord::class, 'json');
    }

    /**
     * Verifiziert absichtlich erfolglos gegen ein Fake-Credential, damit eine
     * unbekannte Credential-ID die Zeremonie ebenso durchläuft wie eine bekannte.
     *
     * Angeglichen wird die Zeremonie ab `CheckClientDataCollectorType`. Der Schritt
     * davor, `CheckUserHandle`, bleibt asymmetrisch: Er vergleicht gegen den
     * gespeicherten Handle, den ein Fake-Credential nicht kennen kann. Passt der
     * mitgeschickte Handle nicht, bricht der bekannte Pfad dort ab, während der Fake
     * bis zur Signaturprüfung weiterläuft. Bewusst in Kauf genommen: TR-03188 NRM-7
     * zielt auf Rückschlüsse darauf, ob ein Benutzer registriert ist, und dazu trägt
     * eine zufällige Credential-ID nichts bei. Aus demselben Grund bildet der Fake
     * auch die Deserialisierung des gespeicherten Records nicht nach.
     */
    private function runFakeVerification(
        AuthenticatorAssertionResponse $response,
        PublicKeyCredentialRequestOptions $storedOptions,
        string $host,
    ): void {
        try {
            $fakeSource = CredentialRecord::create(
                publicKeyCredentialId: random_bytes(32),
                type: 'public-key',
                transports: [],
                attestationType: 'none',
                trustPath: new EmptyTrustPath(),
                aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                credentialPublicKey: pack('H*', self::FAKE_COSE_PUBLIC_KEY_HEX),
                // Handle aus der Antwort übernehmen, damit der Fake wie eine reguläre
                // Anmeldung bis zur Signaturprüfung durchläuft; ein zufälliger würde
                // ihn schon in `CheckUserHandle` abbrechen lassen.
                userHandle: $response->userHandle ?? random_bytes(16),
                counter: 0,
            );

            $validator = $this->validatorFactory->buildAssertionValidator(WebauthnConfig::appUrl());
            $validator->check($fakeSource, $response, $storedOptions, $host, $response->userHandle);
            // Hier anzukommen hieße, die Fake-Verifikation wäre unerwartet
            // durchgegangen. Unkritisch: Der Aufrufer wirft ohnehin weiter.
        } catch (AuthenticatorResponseVerificationException) {
            // Erwartet — das Fake-Credential ist auf Ablehnung ausgelegt.
        } catch (\Throwable $e) {
            // Unerwarteter Fehler (etwa eine Inkompatibilität der Bibliothek):
            // melden, aber nicht weiterwerfen. Der Aufrufer verweigert so oder so.
            report($e);
        }
    }
}
