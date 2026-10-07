<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

/**
 * Steht für einen Fehler, den die Bibliothek nicht als abgelehnte Antwort meldet,
 * sondern als beliebige Exception.
 */
final class FailingValidatorFactory implements WebAuthnValidatorFactoryContract
{
    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator
    {
        throw new \RuntimeException('No attestation validator for ' . $appUrl);
    }

    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator
    {
        throw new \RuntimeException('No assertion validator for ' . $appUrl);
    }
}
