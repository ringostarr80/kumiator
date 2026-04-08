<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\PasskeyRegistrationService;
use App\Services\WebAuthn\WebAuthnServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialCreationOptions;

final class PasskeyRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyRegistrationService $service;

    public function testCreateOptionsReturnsCreationOptions(): void
    {
        $user = User::factory()->create();

        $options = $this->service->createOptions($user);

        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $options);
        $this->assertSame($user->getWebAuthnUserHandle(), $options->user->id);
    }

    public function testCreateOptionsExcludesAlreadyRegisteredCredentials(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        $options = $this->service->createOptions($user);

        $this->assertCount(2, $options->excludeCredentials);
    }

    public function testCreateOptionsDoesNotExcludeOtherUsersCredentials(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PasskeyCredential::factory()->for($other)->count(3)->create();

        $options = $this->service->createOptions($user);

        $this->assertEmpty($options->excludeCredentials);
    }

    public function testCreateOptionsGeneratesNonEmptyChallenge(): void
    {
        $user = User::factory()->create();

        $options = $this->service->createOptions($user);

        $this->assertNotEmpty($options->challenge);
        $this->assertGreaterThanOrEqual(16, strlen($options->challenge));
    }

    public function testCreateOptionsGeneratesDifferentChallengesOnEachCall(): void
    {
        $user = User::factory()->create();

        $optionsA = $this->service->createOptions($user);
        $optionsB = $this->service->createOptions($user);

        $this->assertNotSame($optionsA->challenge, $optionsB->challenge);
    }

    public function testVerifyAndSaveThrowsForInvalidResponseType(): void
    {
        $user = User::factory()->create();
        $options = $this->service->createOptions($user);
        $rawId = random_bytes(32);

        // An assertion response (webauthn.get) carries "authenticatorData" + "signature".
        // verifyAndSave() expects an AuthenticatorAttestationResponse and must reject this.
        $clientDataJson = Base64UrlSafe::encodeUnpadded(
            (string) json_encode([
                'type' => 'webauthn.get',
                'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
                'origin' => 'https://localhost',
            ]),
        );

        // authenticatorData has a fixed binary structure:
        // 32 bytes RP-ID hash | 1 byte flags (UP+UV = 0x05) | 4 bytes sign counter
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
        $this->expectExceptionMessage(__('app.passkey_invalid_response_type'));

        $this->service->verifyAndSave($user, $rawResponse, $options, 'Test Key', 'localhost');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $serverService = app(WebAuthnServerService::class);

        $this->service = new PasskeyRegistrationService(
            $serverService,
            new PasskeyCredentialRepository($serverService->getSerializer()),
        );
    }
}
