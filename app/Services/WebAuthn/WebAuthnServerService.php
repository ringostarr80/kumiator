<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Configures and provides the core WebAuthn validator services.
 *
 * This is the only place in the application that knows how to bootstrap the
 * webauthn-lib ceremony machinery. Both validators are stateless and safe
 * to keep as singletons inside the service container.
 */
final class WebAuthnServerService
{
    private readonly AttestationStatementSupportManager $attestationManager;

    private readonly SerializerInterface $serializer;

    public function __construct()
    {
        // Only "none" attestation is required for passkeys.
        // Add more supports here if you need hardware-backed attestation.
        $this->attestationManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);

        $this->serializer = (new WebauthnSerializerFactory($this->attestationManager))->create();
    }

    /**
     * Returns the Symfony Serializer pre-configured with all WebAuthn normalizers.
     * Use this to deserialise browser responses and serialise options/credentials.
     */
    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    /**
     * Normalise a serialised WebAuthn options JSON string into a clean PHP
     * array suitable for JSON responses.
     *
     * The webauthn-lib serializer emits null for optional fields that are not
     * configured. The browser's parseCreationOptionsFromJSON /
     * parseRequestOptionsFromJSON coerces null to the string "null" via WebIDL,
     * causing RP ID mismatches and other ceremony errors. This method strips
     * those nulls before the array leaves the server.
     *
     * Pass the same JSON string that was stored in the session to avoid
     * serialising the options object twice.
     *
     * @return array<mixed>
     */
    public function normalizeOptionsJson(string $json): array
    {
        $decoded = json_decode($json, true);

        $stripped = WebAuthnJsonNormalizer::stripNulls(is_array($decoded) ? $decoded : []);

        return is_array($stripped)
            ? $stripped
            : [];
    }

    /**
     * Build a validator for the registration (attestation) ceremony.
     * A new instance is created each time because it is lightweight.
     */
    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator
    {
        $factory = $this->buildConfiguredStepManagerFactory($appUrl);

        return AuthenticatorAttestationResponseValidator::create(
            $factory->creationCeremony(),
        );
    }

    /**
     * Build a validator for the authentication (assertion) ceremony.
     */
    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator
    {
        $factory = $this->buildConfiguredStepManagerFactory($appUrl);

        return AuthenticatorAssertionResponseValidator::create(
            $factory->requestCeremony(),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function buildConfiguredStepManagerFactory(string $appUrl): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();

        // Tell the factory which origins are valid so that CheckOrigin /
        // CheckAllowedOrigins passes during both ceremonies.
        $factory->setAllowedOrigins([$appUrl]);

        return $factory;
    }
}
