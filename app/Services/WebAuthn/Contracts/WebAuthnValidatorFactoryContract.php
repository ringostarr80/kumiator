<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

interface WebAuthnValidatorFactoryContract
{
    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator;

    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator;
}
