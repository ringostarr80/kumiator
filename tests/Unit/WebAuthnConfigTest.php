<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebAuthn\WebAuthnConfig;
use Tests\TestCase;

final class WebAuthnConfigTest extends TestCase
{
    public function testRpIdReturnsNullWhenNotConfigured(): void
    {
        config(['webauthn.relying_party.id' => null]);

        $this->assertNull(WebAuthnConfig::rpId());
    }

    public function testRpIdReturnsStringWhenConfigured(): void
    {
        config(['webauthn.relying_party.id' => 'example.com']);

        $this->assertSame('example.com', WebAuthnConfig::rpId());
    }

    public function testRpIdReturnsNullForNonStringValue(): void
    {
        config(['webauthn.relying_party.id' => 42]);

        $this->assertNull(WebAuthnConfig::rpId());
    }

    public function testAppUrlReturnsConfiguredString(): void
    {
        config(['app.url' => 'https://example.com']);

        $this->assertSame('https://example.com', WebAuthnConfig::appUrl());
    }

    public function testAppUrlReturnsFallbackForNonStringValue(): void
    {
        config(['app.url' => null]);

        $this->assertSame('', WebAuthnConfig::appUrl());
    }
}
