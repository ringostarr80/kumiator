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
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Orchestrates the WebAuthn registration (attestation) ceremony.
 *
 * Flow:
 *   1. Call createOptions() to get a PublicKeyCredentialCreationOptions object.
 *      Serialise it to JSON and send to the browser. Store it in the session.
 *   2. The browser calls navigator.credentials.create() and POSTs the result.
 *   3. Call verifyAndSave() with the raw JSON, the stored options, and a
 *      user-chosen name for the new passkey.
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
     * Build the creation options that must be sent to the browser to start the
     * registration ceremony.
     *
     * The returned object must be serialised to JSON (using the WebAuthn
     * serializer) and stored in the session so that verifyAndSave() can
     * validate the browser response against the original challenge.
     */
    public function createOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $rpEntity = PublicKeyCredentialRpEntity::create(
            name: WebauthnConfig::rpName(),
            id: WebauthnConfig::rpId(),
        );

        // The user handle must be a stable, opaque identifier – NOT the e-mail
        // address. We use the primary key of the user.
        $userEntity = PublicKeyCredentialUserEntity::create(
            name: $user->email,
            id: $user->getWebAuthnUserHandle(),
            displayName: $user->name,
        );

        // Prefer ES256 (ECDSA P-256) which is supported by all modern passkey
        // providers; RS256 is the fallback for Windows Hello / TPM-backed keys.
        $pubKeyCredParams = [
            PublicKeyCredentialParameters::create('public-key', ES256::ID),
            PublicKeyCredentialParameters::create('public-key', RS256::ID),
        ];

        // Exclude already-registered credentials so that the same authenticator
        // cannot be registered twice for the same user.
        $excludeCredentials = PasskeyDescriptorBuilder::fromCollection(
            $this->repository->findAllForUser($user),
        );

        $timeout = WebauthnConfig::timeoutMs();

        return PublicKeyCredentialCreationOptions::create(
            rp: $rpEntity,
            user: $userEntity,
            challenge: random_bytes(32),
            pubKeyCredParams: $pubKeyCredParams,
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                userVerification: WebauthnConfig::userVerification(),
            ),
            attestation: WebauthnConfig::attestationConveyance(),
            excludeCredentials: $excludeCredentials,
            timeout: $timeout,
        );
    }

    /**
     * Validate the browser response and persist the new credential.
     *
     * @param string $rawResponse JSON string as received from the browser
     * @param PublicKeyCredentialCreationOptions $storedOptions The options that
     *        were stored in the session when createOptions() was called
     * @param string $credentialName User-chosen label for this passkey
     * @param string $host The effective domain (e.g. "localhost" or "example.com")
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
            throw new AuthenticatorResponseVerificationException(__('app.passkey_invalid_response_type'));
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
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildNewCredentialData(PublicKeyCredentialSource $record): NewPasskeyCredentialData
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
