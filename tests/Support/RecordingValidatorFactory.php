<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\WebAuthn\Contracts\WebAuthnValidatorFactoryContract;
use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

/**
 * Reicht den mitschneidenden Assertion-Validator durch — und zwar denselben für
 * jeden Aufruf, damit echter Pfad und Fake-Pfad in einem Mitschnitt landen.
 */
final class RecordingValidatorFactory implements WebAuthnValidatorFactoryContract
{
    private ?RecordingAssertionValidator $assertionValidator = null;

    public function buildAttestationValidator(string $appUrl): AuthenticatorAttestationResponseValidator
    {
        return $this->realFactory()->buildAttestationValidator($appUrl);
    }

    public function buildAssertionValidator(string $appUrl): AuthenticatorAssertionResponseValidator
    {
        if ($this->assertionValidator === null) {
            $factory = $this->realFactory()->buildConfiguredStepManagerFactory($appUrl);

            $this->assertionValidator = new RecordingAssertionValidator($factory->requestCeremony());
        }

        return $this->assertionValidator;
    }

    /**
     * @return list<array{record: \Webauthn\CredentialRecord, userHandle: string|null, error: \Throwable|null}>
     */
    public function recordedCalls(): array
    {
        return $this->assertionValidator?->calls() ?? [];
    }

    private function realFactory(): WebAuthnValidatorFactory
    {
        return new WebAuthnValidatorFactory(app(AttestationStatementSupportManager::class));
    }
}
