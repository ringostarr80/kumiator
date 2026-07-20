<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

final class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string AUTHENTICATE_OPTIONS_URL = '/passkeys/authenticate/options';
    private const string AUTHENTICATE_URL = '/passkeys/authenticate';

    public function testAuthenticateEndpointIsRateLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::AUTHENTICATE_URL)->assertStatus(422);
        }

        $this->postJson(self::AUTHENTICATE_URL)->assertTooManyRequests();
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

    public function testOptionsEndpointRunsFakeCredentialLookupForUnknownEmail(): void
    {
        DB::enableQueryLog();

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL . '?email=unknown@example.com')
            ->assertOk();

        $credentialQueries = array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains($q['query'], 'passkey_credentials'),
        );

        DB::disableQueryLog();

        $this->assertNotEmpty($credentialQueries, 'Expected a fake passkey_credentials query for an unknown e-mail.');
    }

    public function testOptionsEndpointReturns422ForInvalidEmail(): void
    {
        $response = $this->getJson(self::AUTHENTICATE_OPTIONS_URL . '?email=not-an-email');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
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

    public function testAuthenticateReturns422WhenSessionHasExpired(): void
    {
        // Simulate a ceremony whose TTL has already elapsed.
        $response = $this->withSession([
            'webauthn.authentication.options' => [
                'data' => '{"challenge":"dGVzdA"}',
                'expires_at' => now()->subMinutes(5)->timestamp,
            ],
        ])->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertUnprocessable();
    }

    public function testAuthenticateReturns422WhenSessionDataIsCorrupted(): void
    {
        // A non-array value in the session key must be treated as missing/invalid.
        $response = $this->withSession([
            'webauthn.authentication.options' => 'corrupted-session-string',
        ])->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertUnprocessable();
    }

    public function testAuthenticateReturns400WhenRequestBodyIsEmpty(): void
    {
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response = $this->call(
            'POST',
            self::AUTHENTICATE_URL,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: '',
        );

        $response->assertBadRequest();
    }

    // The following tests mock PasskeyAuthenticationContract::verify() because a real
    // WebAuthn assertion requires a valid cryptographic signature produced by an actual
    // authenticator. Reproducing such a response in an automated test environment is not
    // practically possible, so mocking the verification boundary is the accepted exception
    // to the project's "avoid mocks" guideline for these specific controller code paths.

    public function testAuthenticateReturns422WhenVerificationFails(): void
    {
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $this->partialMock(PasskeyAuthenticationContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->andThrow(
                new AuthenticatorResponseVerificationException('Verification failed.'),
            );
        });

        $response = $this->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertUnprocessable();
        $response->assertJson(['message' => 'Verification failed.']);
    }

    public function testAuthenticateReturns500WhenUnexpectedExceptionOccurs(): void
    {
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $this->partialMock(PasskeyAuthenticationContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->andThrow(new \RuntimeException('Unexpected error.'));
        });

        $response = $this->postJson(self::AUTHENTICATE_URL, ['data' => 'test']);

        $response->assertInternalServerError();
    }

    public function testSuccessfulAuthenticationLogsInUser(): void
    {
        $user = User::factory()->create();
        $credential = PasskeyCredential::factory()->for($user)->create();

        // Populate the session via the real options endpoint
        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $this->partialMock(
            PasskeyAuthenticationContract::class,
            function (MockInterface $mock) use ($credential): void {
                $mock->shouldReceive('verify')->andReturn($credential);
                // `loginAuthenticatedUser` ist nun Teil des Contracts; im
            // Test-Doppel muss es gegen das echte `Auth::login()` delegieren,
            // damit `assertAuthenticatedAs()` greift. Der Controller übergibt
            // den aus der Credential abgeleiteten Owner — daher kein `->with()`.
                $mock->shouldReceive('loginAuthenticatedUser')
                    ->andReturnUsing(function (User $u): void {
                        Auth::login($u);
                    });
            },
        );

        $response = $this->postJson(self::AUTHENTICATE_URL, []);

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);

        // Erfolgseintrag entsteht erst nach bestandenem Gate im Controller.
        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'passkey')
                ->where('event', 'passkey_login_succeeded')
                ->where('subject_id', $credential->getKey())
                ->count(),
        );
    }

    /**
     * `recordSuccessfulLoginActivity()` läuft, nachdem `Auth::login()` die
     * Session bereits umgestellt hat. Bricht der Audit-Insert, darf das den
     * abgeschlossenen Login nicht als 500 verkleiden: Der Browser bekäme kein
     * `redirect`-Feld und bliebe auf der Login-Seite stehen, obwohl der Nutzer
     * serverseitig angemeldet ist.
     */
    public function testSuccessfulAuthenticationSurvivesFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->create();
        $credential = PasskeyCredential::factory()->for($user)->create();

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        // `loginAuthenticatedUser` an die echte Implementierung delegieren statt
        // `Auth::login()` nachzubauen: Nur sie setzt den Passkey-Marker, der den
        // `LogAuthenticationActivityListener` stillhält. Ohne ihn schlüge schon
        // dessen Insert fehl und der Test träfe den falschen Pfad.
        $realService = $this->app->make(PasskeyAuthenticationService::class);

        $this->partialMock(
            PasskeyAuthenticationContract::class,
            function (MockInterface $mock) use ($credential, $realService): void {
                $mock->shouldReceive('verify')->andReturn($credential);
                $mock->shouldReceive('loginAuthenticatedUser')
                    ->andReturnUsing($realService->loginAuthenticatedUser(...));
            },
        );

        Schema::drop('activity_log');

        $response = $this->postJson(self::AUTHENTICATE_URL, []);

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);
        Exceptions::assertReported(QueryException::class);
    }

    public function testUnapprovedUserCannotAuthenticateViaPasskey(): void
    {
        $user = User::factory()->unapproved()->create();
        $credential = PasskeyCredential::factory()->for($user)->create();

        $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $this->partialMock(
            PasskeyAuthenticationContract::class,
            function (MockInterface $mock) use ($credential): void {
                $mock->shouldReceive('verify')->andReturn($credential);
            },
        );

        $response = $this->postJson(self::AUTHENTICATE_URL, []);

        $response->assertUnauthorized();
        $this->assertGuest();

        // Kern des Fixes: Ein vom Freischaltungs-Gate abgewiesener Passkey-Login
        // darf KEINEN `passkey_login_succeeded`-Eintrag erzeugen — sonst entstünde
        // eine widersprüchliche Audit-Spur (Erfolg + `login_unapproved`, aber
        // ohne Session).
        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'passkey')
                ->where('event', 'passkey_login_succeeded')
                ->count(),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear the IP-based rate limiter between tests so requests from the
        // shared test IP (127.0.0.1) don't accumulate across test methods.
        RateLimiter::clear('passkey-authenticate');
    }
}
