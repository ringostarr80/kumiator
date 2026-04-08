<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\PasskeyRegistrationContract;
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
            ->postJson(self::REGISTER_URL, [], ['Content-Type' => 'application/json']);

        $response->assertUnprocessable();
    }

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
            ->postJson(self::REGISTER_URL, ['name' => 'Test Passkey'], ['Content-Type' => 'application/json']);

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
            server: ['HTTP_ACCEPT' => 'application/json'],
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
            ->postJson(self::REGISTER_URL, ['data' => 'test'], ['Content-Type' => 'application/json']);

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'Verification failed.']);
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
            ->postJson(self::REGISTER_URL, ['data' => 'test'], ['Content-Type' => 'application/json']);

        $response->assertInternalServerError();
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
            ->postJson(self::REGISTER_URL, [], ['Content-Type' => 'application/json'])
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
            ->postJson(self::REGISTER_URL, ['name' => '   '], ['Content-Type' => 'application/json'])
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
