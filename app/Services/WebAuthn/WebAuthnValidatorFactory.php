<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * Creates WebAuthn ceremony validators for registration and authentication.
 *
 * This is a pure factory — it has no state and simply configures the
 * webauthn-lib ceremony machinery with the allowed origins.
 */
final class WebAuthnValidatorFactory implements WebAuthnValidatorFactoryContract
{
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
