<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Tests\TestCase;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

final class WebAuthnValidatorFactoryTest extends TestCase
{
    private WebAuthnValidatorFactory $factory;

    public function testBuildAttestationValidatorReturnsValidator(): void
    {
        $validator = $this->factory->buildAttestationValidator('https://localhost:8443');

        $this->assertInstanceOf(AuthenticatorAttestationResponseValidator::class, $validator);
    }

    public function testBuildAssertionValidatorReturnsValidator(): void
    {
        $validator = $this->factory->buildAssertionValidator('https://localhost:8443');

        $this->assertInstanceOf(AuthenticatorAssertionResponseValidator::class, $validator);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new WebAuthnValidatorFactory();
    }
}
