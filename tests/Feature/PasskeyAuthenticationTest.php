<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\Contracts\PasskeyAuthenticationContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialRequestOptions;

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

    /**
     * Die Antwort ist für jeden Aufrufer dieselbe; eine gefüllte
     * `allowCredentials`-Liste machte daraus eine Auskunft über registrierte
     * Konten.
     */
    public function testOptionsEndpointNeverListsCredentials(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->getJson(self::AUTHENTICATE_OPTIONS_URL);

        $response->assertOk();
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

    public function testAuthenticateReturns422WhenVerificationFails(): void
    {
        $user = User::factory()->create();
        $credential = VirtualAuthenticator::create()->registerFor($user);
        $options = $this->requestOptions();

        // Ein fremder Authenticator signiert für einen Passkey, der ihm nicht gehört.
        $response = $this->postAssertion(
            VirtualAuthenticator::create()->signAssertion($credential, $options),
        );

        $response->assertUnprocessable();
        $response->assertJson(['message' => __('app.passkey_auth_error')]);
    }

    /**
     * Der Abbruchgrund darf die Antwort nicht unterscheidbar machen: Wer eine
     * Credential-ID besitzt, läse sonst am Antworttext ab, ob sie hier
     * registriert ist — ohne jede Zeitmessung.
     */
    public function testAnUnknownCredentialAnswersLikeAWrongSignature(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $wrongSignature = $this->postAssertion(
            VirtualAuthenticator::create()->signAssertion($credential, $this->requestOptions()),
        );

        // Gültig signiert, aber der Datensatz ist zwischenzeitlich verschwunden.
        $assertion = $authenticator->signAssertion($credential, $this->requestOptions());
        $credential->deleteOrFail();
        $unknownCredential = $this->postAssertion($assertion);

        $wrongSignature->assertUnprocessable();
        $unknownCredential->assertUnprocessable();
        $this->assertSame($wrongSignature->json('message'), $unknownCredential->json('message'));
    }

    public function testTheLibraryReasonIsLoggedButNotSentToTheBrowser(): void
    {
        $user = User::factory()->create();
        $credential = VirtualAuthenticator::create()->registerFor($user);

        $response = $this->postAssertion(
            VirtualAuthenticator::create()->signAssertion($credential, $this->requestOptions()),
        );

        $activity = Activity::query()
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);

        $rawDetail = $activity->properties?->get('failure_detail');
        $detail = is_string($rawDetail)
            ? $rawDetail
            : '';

        // Ohne den Bibliotheks-Grund im Log wäre die generische Antwort nicht mehr zu debuggen.
        $this->assertNotSame('', $detail);
        $this->assertStringNotContainsString($detail, $response->content());
    }

    /**
     * Der interne Fehler wird gemockt, weil ein Defekt unterhalb der
     * Verifikation (etwa ein Serialisierungsfehler der Bibliothek) sich mit
     * einer wohlgeformten Antwort nicht auslösen lässt.
     */
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
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->requestOptions();

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

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
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->requestOptions();

        Schema::drop('activity_log');

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);
        $this->assertAuthenticatedAs($user);
        Exceptions::assertReported(QueryException::class);
    }

    public function testUnapprovedUserCannotAuthenticateViaPasskey(): void
    {
        $user = User::factory()->unapproved()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->requestOptions();

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

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

    /**
     * Holt die Optionen über den echten Endpunkt — nur so landet die Challenge
     * in der Session, gegen die der spätere POST geprüft wird.
     */
    private function requestOptions(): PublicKeyCredentialRequestOptions
    {
        $content = $this->getJson(self::AUTHENTICATE_OPTIONS_URL)->content();

        return app(SerializerInterface::class)->deserialize($content, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * Sendet die Assertion als rohen JSON-Body, wie es der Browser tut.
     *
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function postAssertion(string $rawResponse): TestResponse
    {
        return $this->call(
            'POST',
            self::AUTHENTICATE_URL,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $rawResponse,
        );
    }
}
