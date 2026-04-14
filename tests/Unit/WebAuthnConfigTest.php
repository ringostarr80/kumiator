<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use Tests\TestCase;

final class WebAuthnConfigTest extends TestCase
{
    public function testRpIdReturnsNullWhenNotConfigured(): void
    {
        config(['webauthn.relying_party.id' => null]);

        $this->assertNull(WebauthnConfig::rpId());
    }

    public function testRpIdReturnsStringWhenConfigured(): void
    {
        config(['webauthn.relying_party.id' => 'example.com']);

        $this->assertSame('example.com', WebauthnConfig::rpId());
    }

    public function testRpIdReturnsNullForNonStringValue(): void
    {
        config(['webauthn.relying_party.id' => 42]);

        $this->assertNull(WebauthnConfig::rpId());
    }

    public function testAppUrlReturnsConfiguredString(): void
    {
        config(['app.url' => 'https://example.com']);

        $this->assertSame('https://example.com', WebauthnConfig::appUrl());
    }

    public function testAppUrlReturnsFallbackForNonStringValue(): void
    {
        config(['app.url' => null]);

        $this->assertSame('', WebauthnConfig::appUrl());
    }

    public function testEffectiveHostPrefersRpId(): void
    {
        config(['webauthn.relying_party.id' => 'example.com', 'app.url' => 'https://other.com']);

        $this->assertSame('example.com', WebauthnConfig::effectiveHost());
    }

    public function testEffectiveHostFallsBackToAppUrlHost(): void
    {
        config(['webauthn.relying_party.id' => null, 'app.url' => 'https://app.example.com']);

        $this->assertSame('app.example.com', WebauthnConfig::effectiveHost());
    }

    public function testEffectiveHostReturnsEmptyStringWhenNeitherIsSet(): void
    {
        config(['webauthn.relying_party.id' => null, 'app.url' => '']);

        $this->assertSame('', WebauthnConfig::effectiveHost());
    }

    public function testEffectiveHostReturnsEmptyStringForMalformedUrl(): void
    {
        config(['webauthn.relying_party.id' => null, 'app.url' => 'not-a-url']);

        $this->assertSame('', WebauthnConfig::effectiveHost());
    }
}
