<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebAuthn\WebAuthnServerService;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\TestCase;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;

final class WebAuthnServerServiceTest extends TestCase
{
    private WebAuthnServerService $service;

    public function testGetSerializerReturnsSerializer(): void
    {
        $this->assertInstanceOf(SerializerInterface::class, $this->service->getSerializer());
    }

    public function testBuildAttestationValidatorReturnsValidator(): void
    {
        $validator = $this->service->buildAttestationValidator('https://localhost:8443');

        $this->assertInstanceOf(AuthenticatorAttestationResponseValidator::class, $validator);
    }

    public function testBuildAssertionValidatorReturnsValidator(): void
    {
        $validator = $this->service->buildAssertionValidator('https://localhost:8443');

        $this->assertInstanceOf(AuthenticatorAssertionResponseValidator::class, $validator);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WebAuthnServerService();
    }
}
