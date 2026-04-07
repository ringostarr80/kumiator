<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

final class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string AUTHENTICATE_OPTIONS_URL = '/passkeys/authenticate/options';
    private const string AUTHENTICATE_URL = '/passkeys/authenticate';

    public function testAuthenticationEndpointsAreRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->getJson(self::AUTHENTICATE_OPTIONS_URL)->assertOk();
        }

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL)->assertTooManyRequests();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Options endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testOptionsEndpointRedirectsAuthenticatedUsers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(self::AUTHENTICATE_OPTIONS_URL);

        // Authenticated users should be redirected away from guest routes
        $response->assertRedirect();
    }

    public function testOptionsEndpointReturnsRequestOptionsForGuests(): void
    {
        $response = $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response->assertOk();
        $response->assertJsonStructure(['challenge']);
    }

    public function testOptionsEndpointAcceptsEmailParameterToNarrowCredentials(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->getJson(self::AUTHENTICATE_OPTIONS_URL . '?email=' . urlencode($user->email));

        $response->assertOk();
        $response->assertJsonStructure(['challenge', 'allowCredentials']);
    }

    public function testOptionsEndpointWithUnknownEmailReturnsOptionsWithEmptyAllowCredentials(): void
    {
        $response = $this->getJson(self::AUTHENTICATE_OPTIONS_URL . '?email=unknown@example.com');

        $response->assertOk();
        $response->assertJsonStructure(['challenge']);
        $response->assertJsonCount(0, 'allowCredentials');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Authenticate endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testAuthenticateEndpointRedirectsAuthenticatedUsers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(self::AUTHENTICATE_URL, []);

        $response->assertRedirect();
    }

    public function testAuthenticateReturns422WhenSessionIsMissing(): void
    {
        $response = $this->withSession([])
            ->postJson(self::AUTHENTICATE_URL, []);

        $response->assertUnprocessable();
    }

    public function testAuthenticateReturns400WhenRequestBodyIsEmpty(): void
    {
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->call(
            'POST',
            self::AUTHENTICATE_URL,
            server: ['HTTP_ACCEPT' => 'application/json'],
            content: '',
        );

        $response->assertBadRequest();
    }

    public function testAuthenticateReturns422WhenVerificationFails(): void
    {
        $this->partialMock(PasskeyAuthenticationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->andThrow(
                new AuthenticatorResponseVerificationException('Verification failed.'),
            );
        });

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'Verification failed.']);
    }

    public function testAuthenticateReturns500WhenUnexpectedExceptionOccurs(): void
    {
        $this->partialMock(PasskeyAuthenticationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->andThrow(new \RuntimeException('Unexpected error.'));
        });

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertInternalServerError();
    }

    public function testSuccessfulAuthenticationLogsInUser(): void
    {
        $user = User::factory()->create();

        $this->partialMock(PasskeyAuthenticationService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('verify')->andReturn($user);
        });

        // Populate the session via the real options endpoint
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->postJson(self::AUTHENTICATE_URL, []);

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);
    }

    public function testUnapprovedUserCannotAuthenticateViaPasskey(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->partialMock(PasskeyAuthenticationService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('verify')->andReturn($user);
        });

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->postJson(self::AUTHENTICATE_URL, []);

        $response->assertUnauthorized();
        $this->assertGuest();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear the IP-based rate limiter between tests so requests from the
        // shared test IP (127.0.0.1) don't accumulate across test methods.
        RateLimiter::clear('passkey-authenticate');
    }
}
