<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\Contracts\PasskeyRegistrationContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

final class PasskeyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_OPTIONS_URL = '/user/passkeys/register/options';
    private const string REGISTER_URL = '/user/passkeys/register';
    private const string CONTENT_TYPE_JSON = 'application/json';

    public function testRegistrationEndpointsAreRateLimited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL)->assertOk();
        }

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL)->assertTooManyRequests();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Options endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testOptionsEndpointRequiresAuthentication(): void
    {
        $response = $this->getJson(self::REGISTER_OPTIONS_URL);

        $response->assertUnauthorized();
    }

    public function testOptionsEndpointAbortsWhenAuthUserIsNotAUserInstance(): void
    {
        // Auth::user() returns null when no user is logged in. withoutMiddleware()
        // lets the request reach the controller so the instanceof guard on line 50
        // is exercised instead of the auth middleware returning 401 first.
        $response = $this->withoutMiddleware()->getJson(self::REGISTER_OPTIONS_URL);

        $response->assertUnauthorized();
    }

    public function testOptionsEndpointReturnsCreationOptions(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $response->assertOk();
        $response->assertJsonStructure(['challenge', 'rp', 'user', 'pubKeyCredParams']);
    }

    public function testOptionsEndpointStoresChallengeInSession(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->assertNotNull(session('webauthn.registration.options'));
    }

    public function testOptionsEndpointExcludesAlreadyRegisteredCredentials(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        $response = $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $response->assertOk();
        $response->assertJsonCount(2, 'excludeCredentials');
    }

    public function testSubsequentOptionsRequestsProduceFreshChallenges(): void
    {
        // Each call to the options endpoint must generate a new random challenge.
        // A repeated challenge would allow replay attacks.
        $user = User::factory()->create();

        $first = $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL)->json('challenge');
        $second = $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL)->json('challenge');

        $this->assertNotSame($first, $second, 'Each options request must produce a unique challenge.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Store endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testStoreEndpointRequiresAuthentication(): void
    {
        $response = $this->postJson(self::REGISTER_URL, []);

        $response->assertUnauthorized();
    }

    public function testStoreEndpointReturns422WhenSessionIsMissing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([])
            ->postJson(self::REGISTER_URL, [], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertUnprocessable();
    }

    public function testStoreEndpointReturns422WhenSessionHasExpired(): void
    {
        $user = User::factory()->create();

        // Simulate a ceremony whose TTL has already elapsed.
        $response = $this->actingAs($user)
            ->withSession([
                'webauthn.registration.options' => [
                    'data' => '{"challenge":"dGVzdA"}',
                    'expires_at' => now()->subMinutes(5)->timestamp,
                ],
            ])->postJson(self::REGISTER_URL, [], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertUnprocessable();
    }

    public function testStoreEndpointReturns422WhenSessionDataIsCorrupted(): void
    {
        $user = User::factory()->create();

        // A non-array value in the session key must be treated as missing/invalid.
        $response = $this->actingAs($user)
            ->withSession([
                'webauthn.registration.options' => 'corrupted-session-string',
            ])->postJson(self::REGISTER_URL, [], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertUnprocessable();
    }

    // The following tests mock PasskeyRegistrationContract::verifyAndSave() because a real
    // WebAuthn attestation requires a valid authenticator response with correct CBOR-encoded
    // data signed by the authenticator's private key. Reproducing such a response in an
    // automated test environment is not practically possible, so mocking the verification
    // boundary is the accepted exception to the project's "avoid mocks" guideline for these
    // specific controller code paths.

    public function testStoreEndpointPersistsCredentialOnSuccess(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        // Call the real options endpoint first so the session is populated by the
        // fully initialised service (constructor-injected properties are set).
        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        // Bind the partial mock only after the GET, so the POST goes through the
        // mock while the GET used the real service. verifyAndSave() is the only
        // method called during store(), so uninitialised mock properties are
        // never accessed.
        $this->partialMock(
            PasskeyRegistrationContract::class,
            function (MockInterface $mock) use ($passkey): void {
                $mock->shouldReceive('verifyAndSave')->andReturn($passkey);
            },
        );

        $response = $this->actingAs($user)
            ->postJson(self::REGISTER_URL, ['name' => 'Test Passkey'], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'name', 'created_at']);
    }

    public function testStoreEndpointReturns400WhenRequestBodyIsEmpty(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $response = $this->call(
            'POST',
            self::REGISTER_URL,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => self::CONTENT_TYPE_JSON],
            content: '',
        );

        $response->assertBadRequest();
    }

    public function testStoreEndpointReturns422WhenVerificationFails(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->partialMock(PasskeyRegistrationContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyAndSave')
                ->andThrow(new AuthenticatorResponseVerificationException('Verification failed.'));
        });

        $response = $this->actingAs($user)
            ->postJson(self::REGISTER_URL, ['data' => 'test'], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertUnprocessable();
        $response->assertJson(['message' => __('app.passkey_registration_failed')]);

        // Audit-Symmetrie zum Erfolgs-Pfad (`passkey_registered`): jeder
        // gescheiterte Attest-Verify hinterlässt einen Eintrag mit dem
        // authentifizierten User als Causer und `failure_reason` in den
        // Properties.
        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_registration_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame('verification_failed', $activity->properties?->get('failure_reason'));

        // Der Wortlaut der Bibliothek bleibt auffindbar, erreicht aber den Browser nicht.
        $this->assertSame('Verification failed.', $activity->properties->get('failure_detail'));
        $this->assertStringNotContainsString('Verification failed.', $response->content());
    }

    public function testStoreEndpointReturns500WhenUnexpectedExceptionOccurs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->partialMock(PasskeyRegistrationContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyAndSave')
                ->andThrow(new \RuntimeException('Unexpected error.'));
        });

        $response = $this->actingAs($user)
            ->postJson(self::REGISTER_URL, ['data' => 'test'], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertInternalServerError();

        // Eigener Text: Bei einem Serverfehler hilft nur ein späterer Versuch, beim
        // abgelehnten Attestat dagegen ein anderer Authenticator.
        $response->assertJsonPath('message', __('app.passkey_registration_server_error'));

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_registration_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame('internal_error', $activity->properties?->get('failure_reason'));
    }

    public function testStoreEndpointUsesDefaultNameWhenNameIsOmitted(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->partialMock(
            PasskeyRegistrationContract::class,
            function (MockInterface $mock) use ($passkey): void {
                $mock->shouldReceive('verifyAndSave')
                    ->with(
                        Mockery::any(),
                        Mockery::any(),
                        Mockery::any(),
                        Mockery::on(fn (string $name): bool => $name === __('app.passkey_default_name')),
                        Mockery::any(),
                    )
                    ->andReturn($passkey);
            },
        );

        $this->actingAs($user)
            ->postJson(self::REGISTER_URL, [], ['Content-Type' => self::CONTENT_TYPE_JSON])
            ->assertCreated();
    }

    public function testStoreEndpointUsesDefaultNameWhenNameIsBlank(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->partialMock(
            PasskeyRegistrationContract::class,
            function (MockInterface $mock) use ($passkey): void {
                $mock->shouldReceive('verifyAndSave')
                    ->with(
                        Mockery::any(),
                        Mockery::any(),
                        Mockery::any(),
                        Mockery::on(fn (string $name): bool => $name === __('app.passkey_default_name')),
                        Mockery::any(),
                    )
                    ->andReturn($passkey);
            },
        );

        $this->actingAs($user)
            ->postJson(self::REGISTER_URL, ['name' => '   '], ['Content-Type' => self::CONTENT_TYPE_JSON])
            ->assertCreated();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Destroy endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testDestroyRequiresAuthentication(): void
    {
        $passkey = PasskeyCredential::factory()->create();

        $response = $this->deleteJson("/user/passkeys/{$passkey->id}");

        $response->assertUnauthorized();
    }

    public function testOwnerCanDeletePasskey(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->deleteJson("/user/passkeys/{$passkey->id}");

        $response->assertNoContent();
        $this->assertModelMissing($passkey);
    }

    public function testOtherUserCannotDeletePasskey(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        $response = $this->actingAs($other)->deleteJson("/user/passkeys/{$passkey->id}");

        $response->assertForbidden();
        $this->assertModelExists($passkey);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear the user-based rate limiter between tests for safety,
        // even though each test creates its own user (unique limiter key).
        RateLimiter::clear('passkey-register');
    }
}
