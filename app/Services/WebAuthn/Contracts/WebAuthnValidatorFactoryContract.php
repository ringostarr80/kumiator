<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

interface WebAuthnValidatorFactoryContract
{
    /**
     * Build a validator for the registration (attestation) ceremony.
     */
    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator;

    /**
     * Build a validator for the authentication (assertion) ceremony.
     */
    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator;
}
