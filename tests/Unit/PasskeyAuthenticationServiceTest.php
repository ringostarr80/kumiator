<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyAuthenticationService $service;

    public function testCreateOptionsWithoutUserReturnsEmptyAllowCredentials(): void
    {
        $options = $this->service->createOptions();

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
        $this->assertEmpty($options->allowCredentials);
    }

    public function testCreateOptionsWithUserIncludesUsersCredentials(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        $options = $this->service->createOptions($user);

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
        $this->assertCount(2, $options->allowCredentials);
    }

    public function testCreateOptionsWithUserHavingNoCredentialsReturnsEmptyAllowCredentials(): void
    {
        $user = User::factory()->create();

        $options = $this->service->createOptions($user);

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
        $this->assertEmpty($options->allowCredentials);
    }

    public function testCreateOptionsDoesNotIncludeOtherUsersCredentials(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();
        PasskeyCredential::factory()->for($other)->count(3)->create();

        $options = $this->service->createOptions($user);

        $this->assertCount(2, $options->allowCredentials);
    }

    public function testCreateOptionsGeneratesNonEmptyChallenge(): void
    {
        $options = $this->service->createOptions();

        $this->assertNotEmpty($options->challenge);
        $this->assertGreaterThanOrEqual(16, strlen($options->challenge));
    }

    public function testVerifyThrowsForUnknownCredential(): void
    {
        $options = $this->service->createOptions();
        $rawId = random_bytes(32);

        // clientDataJSON must be a base64url-encoded JSON object (as the browser sends it).
        $clientDataJson = Base64UrlSafe::encodeUnpadded(
            (string) json_encode([
                'type' => 'webauthn.get',
                'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
                'origin' => 'https://localhost',
            ]),
        );

        // authenticatorData has a fixed binary structure the library parses during deserialisation:
        // 32 bytes RP-ID hash | 1 byte flags (UP+UV = 0x05) | 4 bytes sign counter (big-endian)
        $authenticatorData = str_repeat("\x00", 32) . "\x05" . "\x00\x00\x00\x01";

        $rawResponse = (string) json_encode([
            'id' => Base64UrlSafe::encodeUnpadded($rawId),
            'rawId' => Base64UrlSafe::encodeUnpadded($rawId),
            'response' => [
                'clientDataJSON' => $clientDataJson,
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                'signature' => Base64UrlSafe::encodeUnpadded(random_bytes(64)),
            ],
            'type' => 'public-key',
        ]);

        $this->expectException(AuthenticatorResponseVerificationException::class);
        $this->expectExceptionMessageIs(__('app.passkey_credential_not_found'));

        $this->service->verify($rawResponse, $options, 'localhost');
    }

    public function testCreateOptionsGeneratesDifferentChallengesOnEachCall(): void
    {
        $optionsA = $this->service->createOptions();
        $optionsB = $this->service->createOptions();

        $this->assertNotSame($optionsA->challenge, $optionsB->challenge);
    }

    public function testRunFakeCredentialLookupDoesNotThrow(): void
    {
        // Should complete silently with no matching rows.
        $this->service->runFakeCredentialLookup();
        $this->addToAssertionCount(1);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $serializer = app(SerializerInterface::class);

        $this->service = new PasskeyAuthenticationService(
            new WebAuthnValidatorFactory(),
            new PasskeyCredentialRepository(),
            $serializer,
        );
    }
}
