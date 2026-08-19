<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use App\Services\WebAuthn\Contracts\PasskeyRegistrationContract;
use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Orchestriert die WebAuthn-Registrierungszeremonie (Attestation).
 *
 * Ablauf:
 *   1. `createOptions()` liefert die PublicKeyCredentialCreationOptions. Als JSON
 *      an den Browser schicken und in der Session ablegen.
 *   2. Der Browser ruft `navigator.credentials.create()` auf und postet das Ergebnis.
 *   3. `verifyAndSave()` mit dem rohen JSON, den abgelegten Optionen und dem vom
 *      Nutzer gewählten Namen für den neuen Passkey aufrufen.
 */
final class PasskeyRegistrationService implements PasskeyRegistrationContract
{
    public function __construct(
        private readonly WebAuthnValidatorFactoryContract $validatorFactory,
        private readonly PasskeyCredentialRepositoryContract $repository,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Das Ergebnis muss als JSON (über den WebAuthn-Serializer) in die Session,
     * damit `verifyAndSave()` die Browser-Antwort gegen die ursprüngliche
     * Challenge prüfen kann.
     */
    public function createOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $rpEntity = PublicKeyCredentialRpEntity::create(
            name: WebauthnConfig::rpName(),
            id: WebauthnConfig::rpId(),
        );

        // Der User-Handle muss ein stabiler, undurchsichtiger Bezeichner sein –
        // KEINE E-Mail-Adresse. Wir nehmen den Primärschlüssel des Nutzers.
        $userEntity = PublicKeyCredentialUserEntity::create(
            name: $user->email,
            id: $user->getWebAuthnUserHandle(),
            displayName: $user->name,
        );

        // ES256 (ECDSA P-256) zuerst, das beherrschen alle modernen Passkey-
        // Anbieter; RS256 ist der Rückfall für Windows Hello / TPM-Schlüssel.
        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', ES256::ID),
            PublicKeyCredentialParameters::create('public-key', RS256::ID),
        ];

        // Bereits registrierte Credentials ausschließen, damit derselbe
        // Authenticator nicht zweimal für denselben Nutzer angelegt wird.
        $excludeCredentials = PasskeyDescriptorBuilder::fromCollection(
            $this->repository->findAllForUser($user),
        );

        $timeout = WebauthnConfig::timeoutMs();

        return PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: random_bytes(32),
            pubKeyCredParams: $pubKeyCredParams,
            // `required`, weil der Login keine `allowCredentials` sendet: Ein nur
            // serverseitig auffindbarer Passkey ließe sich nie zum Anmelden
            // verwenden. Lieber hier sichtbar scheitern als später stumm.
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
                userVerification: WebauthnConfig::userVerification(),
            ),
            attestation: WebauthnConfig::attestationConveyance(),
            excludeCredentials: $excludeCredentials,
            timeout: $timeout,
        );
    }

    /**
     * @param string $rawResponse JSON-String, wie ihn der Browser schickt
     * @param PublicKeyCredentialCreationOptions $storedOptions Die Optionen, die
     *        beim Aufruf von createOptions() in der Session abgelegt wurden
     * @param string $credentialName Vom Nutzer gewählte Bezeichnung für diesen Passkey
     * @param string $host Die effektive Domain (z. B. "localhost" oder "example.com")
     */
    public function verifyAndSave(
        User $user,
        string $rawResponse,
        PublicKeyCredentialCreationOptions $storedOptions,
        string $credentialName,
        string $host,
    ): PasskeyCredential {
        $publicKeyCredential = $this->serializer->deserialize($rawResponse, PublicKeyCredential::class, 'json');

        $response = $publicKeyCredential->response;

        if (!($response instanceof AuthenticatorAttestationResponse)) {
            throw new AuthenticatorResponseVerificationException('invalid_response_type');
        }

        $validator = $this->validatorFactory->buildAttestationValidator(WebauthnConfig::appUrl());
        $credentialRecord = $validator->check($response, $storedOptions, $host);

        return $this->repository->saveNewCredential(
            $user,
            $this->buildNewCredentialData($credentialRecord),
            $credentialName,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hilfsmethoden
    // ──────────────────────────────────────────────────────────────────────────

    private function buildNewCredentialData(CredentialRecord $record): NewPasskeyCredentialData
    {
        return new NewPasskeyCredentialData(
            credentialId: Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            serializedCredentialSource: $this->serializer->serialize($record, 'json'),
            counter: $record->counter,
            transports: $record->transports,
            backupEligible: $record->backupEligible ?? false,
            backupState: $record->backupStatus ?? false,
            aaguid: $record->aaguid->toRfc4122(),
        );
    }
}
