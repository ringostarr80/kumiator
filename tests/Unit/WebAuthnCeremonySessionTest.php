<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WebAuthn\WebAuthnCeremonySession;
use App\Services\WebAuthn\WebAuthnServerService;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebAuthnCeremonySessionTest extends TestCase
{
    private const SESSION_KEY = 'webauthn.test.options';
    private const string TEST_USER_EMAIL = 'user@test.com';

    private WebAuthnCeremonySession $ceremonySession;

    public function testStoreOptionsWritesEnvelopeToSession(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request);

        $this->assertTrue($request->session()->has(self::SESSION_KEY));
        $stored = $request->session()->get(self::SESSION_KEY);
        $this->assertIsArray($stored);
        $this->assertArrayHasKey('data', $stored);
        $this->assertArrayHasKey('expires_at', $stored);
        $this->assertIsString($stored['data']);
        $this->assertJson($stored['data']);
        $this->assertGreaterThan(now()->timestamp, $stored['expires_at']);
    }

    public function testStoreOptionsReturnsArrayWithoutNullValues(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $result = $this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request);

        $this->assertArrayNotContainsNull($result);
    }

    public function testStoreOptionsRemovesKeyFromSessionAfterPull(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request);
        $this->ceremonySession->pullOptions(self::SESSION_KEY, PublicKeyCredentialRequestOptions::class, $request);

        $this->assertFalse($request->session()->has(self::SESSION_KEY));
    }

    public function testPullOptionsReturnsNullWhenSessionKeyMissing(): void
    {
        $request = $this->makeRequestWithSession();

        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        $this->assertNull($result);
    }

    public function testPullOptionsDeserializesRequestOptions(): void
    {
        $original = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($original, self::SESSION_KEY, $request);
        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $result);
    }

    public function testPullOptionsDeserializesCreationOptions(): void
    {
        $original = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('Test App', 'localhost'),
            user: PublicKeyCredentialUserEntity::create(self::TEST_USER_EMAIL, random_bytes(16), self::TEST_USER_EMAIL),
            challenge: random_bytes(32),
            pubKeyCredParams: [PublicKeyCredentialParameters::create('public-key', ES256::ID)],
        );
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($original, self::SESSION_KEY, $request);
        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialCreationOptions::class,
            $request,
        );

        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $result);
    }

    public function testPullOptionsReturnsNullForMalformedEnvelope(): void
    {
        $request = $this->makeRequestWithSession();
        // Plain string instead of the expected ['data' => ..., 'expires_at' => ...] array
        $request->session()->put(self::SESSION_KEY, 'this-is-not-the-expected-envelope');

        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        $this->assertNull($result);
    }

    public function testPullOptionsReturnsNullForCorruptedJson(): void
    {
        $request = $this->makeRequestWithSession();
        $request->session()->put(self::SESSION_KEY, [
            'data' => 'this-is-not-valid-json{{{',
            'expires_at' => now()->addSeconds(120)->timestamp,
        ]);

        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        $this->assertNull($result);
    }

    public function testPullOptionsReturnsNullWhenCeremonyExpired(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request);

        // Overwrite expires_at with a timestamp in the past
        $stored = $request->session()->get(self::SESSION_KEY);

        if (!is_array($stored)) {
            $this->fail('Expected session to contain an array envelope.');
        }

        $stored['expires_at'] = now()->subSecond()->timestamp;
        $request->session()->put(self::SESSION_KEY, $stored);

        $result = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );

        $this->assertNull($result);
    }

    public function testPullOptionsConsumesSessionEntry(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32));
        $request = $this->makeRequestWithSession();

        $this->ceremonySession->storeOptions($options, self::SESSION_KEY, $request);

        // First pull: should return the options
        $first = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );
        $this->assertNotNull($first);

        // Second pull: session entry has been consumed
        $second = $this->ceremonySession->pullOptions(
            self::SESSION_KEY,
            PublicKeyCredentialRequestOptions::class,
            $request,
        );
        $this->assertNull($second);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ceremonySession = new WebAuthnCeremonySession(new WebAuthnServerService());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeRequestWithSession(): Request
    {
        $request = Request::create('/test', 'GET');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    /**
     * Recursively assert that no value in the array is null.
     *
     * @param array<mixed> $array
     */
    private function assertArrayNotContainsNull(array $array): void
    {
        foreach ($array as $value) {
            $this->assertNotNull($value, 'Result array must not contain null values.');

            if (is_array($value)) {
                $this->assertArrayNotContainsNull($value);
            }
        }
    }
}
