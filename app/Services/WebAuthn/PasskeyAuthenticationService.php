<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Orchestrates the WebAuthn authentication (assertion) ceremony.
 *
 * Flow:
 *   1. Call createOptions() to get a PublicKeyCredentialRequestOptions object.
 *      Serialise it to JSON and send to the browser. Store it in the session.
 *   2. The browser calls navigator.credentials.get() and POSTs the result.
 *   3. Call verify() with the raw JSON, the stored options and an optional
 *      user hint. On success, the authenticated User model is returned.
 */
final class PasskeyAuthenticationService implements PasskeyAuthenticationContract
{
    public function __construct(
        private readonly WebAuthnServerService $serverService,
        private readonly PasskeyCredentialRepository $repository,
    ) {
    }

    /**
     * Build the request options for an authentication ceremony.
     *
     * When $user is provided (e.g. because the user typed their e-mail first),
     * only that user's credentials are included in allowCredentials, which
     * improves UX on browsers that use it for roaming authenticators.
     *
     * Passing no user (discoverable-credential flow) leaves allowCredentials
     * empty so the browser can pick any eligible passkey.
     */
    public function createOptions(?User $user = null): PublicKeyCredentialRequestOptions
    {
        $allowCredentials = [];

        if ($user !== null) {
            foreach ($this->repository->findAllForUser($user) as $credential) {
                $allowCredentials[] = $credential->getDescriptor();
            }
        }

        $timeout = WebAuthnConfig::timeoutMs();

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: WebAuthnConfig::rpId(),
            allowCredentials: $allowCredentials,
            userVerification: WebAuthnConfig::userVerification(),
            timeout: $timeout,
        );
    }

    /**
     * Validate the browser assertion response.
     *
     * Returns the authenticated User on success.
     * Throws AuthenticatorResponseVerificationException on client errors, \LogicException on data integrity violations.
     *
     * @param string $rawResponse JSON string as received from the browser
     * @param PublicKeyCredentialRequestOptions $storedOptions The options stored
     *        in the session when createOptions() was called
     * @param string $host The effective domain (e.g. "localhost")
     */
    public function verify(string $rawResponse, PublicKeyCredentialRequestOptions $storedOptions, string $host): User
    {
        $serializer = $this->serverService->getSerializer();

        $publicKeyCredential = $serializer->deserialize($rawResponse, PublicKeyCredential::class, 'json');

        $response = $publicKeyCredential->response;

        if (!($response instanceof AuthenticatorAssertionResponse)) {
            throw new AuthenticatorResponseVerificationException(
                __('app.passkey_invalid_response_type'),
            );
        }

        // Resolve the credential by its ID (Base64URL-encoded in the browser response)
        $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
        $passkeyModel = $this->repository->findByCredentialId($credentialId);
        $credentialRecord = $passkeyModel !== null
            ? $this->resolvePublicKeyCredentialSource($passkeyModel)
            : null;

        if ($passkeyModel === null || $credentialRecord === null) {
            $this->runFakeVerification($response, $storedOptions, $host);
            throw new AuthenticatorResponseVerificationException(__('app.passkey_credential_not_found'));
        }

        $userHandle = $response->userHandle;
        $validator = $this->serverService->buildAssertionValidator(WebAuthnConfig::appUrl());
        $updatedRecord = $validator->check($credentialRecord, $response, $storedOptions, $host, $userHandle);

        // Persist updated counter and backup flags
        $this->repository->updateAfterAuthentication($passkeyModel, $updatedRecord);

        $user = $passkeyModel->user;

        if ($user === null) {
            throw new \LogicException(__('app.passkey_orphaned_credential'));
        }

        return $user;
    }

    /**
     * Run a fake credential DB lookup to equalise response time between known
     * and unknown e-mail addresses on the options endpoint.
     *
     * The query mirrors PasskeyCredentialRepository::findAllForUser() but uses
     * user ID 0, which is never issued by the auto-increment primary key, so
     * the result is always empty. This prevents timing-based e-mail enumeration.
     */
    public function runFakeCredentialLookup(): void
    {
        $fakeUser = new User();
        $fakeUser->id = 0;
        $this->repository->findAllForUser($fakeUser);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Perform a deliberately failing verification with a fake credential to
     * equalise response time when a credential ID is not found in the database.
     * This prevents timing-based enumeration of valid credential IDs.
     */
    private function runFakeVerification(
        AuthenticatorAssertionResponse $response,
        PublicKeyCredentialRequestOptions $storedOptions,
        string $host,
    ): void {
        try {
            $fakeSource = PublicKeyCredentialSource::create(
                publicKeyCredentialId: random_bytes(32),
                type: 'public-key',
                transports: [],
                attestationType: 'none',
                trustPath: new EmptyTrustPath(),
                aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                // 77 bytes ≈ minimum CBOR-encoded size of an ES256 (EC2/P-256) COSE public key.
                // The value is intentionally invalid; the verification is designed to fail anyway.
                credentialPublicKey: random_bytes(77),
                userHandle: random_bytes(16),
                counter: 0,
            );

            $validator = $this->serverService->buildAssertionValidator(WebAuthnConfig::appUrl());
            $validator->check($fakeSource, $response, $storedOptions, $host, null);
            // If we reach here, the fake verification unexpectedly succeeded.
            // This is safe: the caller still throws credential_not_found.
        } catch (AuthenticatorResponseVerificationException) {
            // Expected — the fake credential is designed to be rejected.
        } catch (\Throwable $e) {
            // Unexpected error (e.g. library incompatibility) — log it, but do not
            // rethrow. The caller denies access regardless.
            report($e);
        }
    }

    private function resolvePublicKeyCredentialSource(PasskeyCredential $model): PublicKeyCredentialSource
    {
        $serializer = $this->serverService->getSerializer();

        return $serializer->deserialize($model->credential_public_key, PublicKeyCredentialSource::class, 'json');
    }
}
