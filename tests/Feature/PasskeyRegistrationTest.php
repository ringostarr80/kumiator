<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

final class PasskeyRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_OPTIONS_URL = '/user/passkeys/register/options';
    private const string REGISTER_URL = '/user/passkeys/register';
    private const string CONTENT_TYPE_JSON = 'application/json';
    private const string SESSION_KEY = 'webauthn.registration.options';

    /** Wortlaut aus CheckUserVerification der webauthn-lib. */
    private const string LIBRARY_UV_REJECTION = 'User authentication required.';

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

        // Erst die Antwort bestimmt, was der Browser verlangt. Ohne dieses
        // Argument setzte `AuthenticatorSelectionCriteria` stillschweigend
        // `preferred`, und die Nutzerverifikation wäre nur noch erwünscht.
        $response->assertJsonPath(
            'authenticatorSelection.userVerification',
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );
    }

    public function testOptionsEndpointStoresChallengeInSession(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL);

        $this->assertNotNull(session(self::SESSION_KEY));
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
                self::SESSION_KEY => [
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
                self::SESSION_KEY => 'corrupted-session-string',
            ])->postJson(self::REGISTER_URL, [], ['Content-Type' => self::CONTENT_TYPE_JSON]);

        $response->assertUnprocessable();
    }

    public function testStoreEndpointPersistsCredentialOnSuccess(): void
    {
        $user = User::factory()->create();
        $options = $this->startCeremony($user);

        $response = $this->actingAs($user)->postJson(
            self::REGISTER_URL,
            // Der Controller liest die Attestation aus dem gesamten Body und den
            // Namen aus demselben JSON, deshalb reisen beide in einem Rumpf.
            [...VirtualAuthenticator::create()->attestation($options), 'name' => 'Test Passkey'],
            ['Content-Type' => self::CONTENT_TYPE_JSON],
        );

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'name', 'created_at']);

        $credential = PasskeyCredential::query()->where('user_id', $user->getKey())->sole();

        $this->assertSame('Test Passkey', $credential->name);
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
        $options = $this->startCeremony($user);

        // Eine Attestation, die der Authenticator ohne Nutzerverifikation liefert:
        // die Zeremonie weist sie ab, ohne dass etwas gemockt werden muss.
        $response = $this->actingAs($user)->postJson(
            self::REGISTER_URL,
            VirtualAuthenticator::create()->attestation($options, userVerified: false),
            ['Content-Type' => self::CONTENT_TYPE_JSON],
        );

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
        $this->assertSame(self::LIBRARY_UV_REJECTION, $activity->properties->get('failure_detail'));
        $this->assertStringNotContainsString(self::LIBRARY_UV_REJECTION, $response->content());
    }

    public function testStoreEndpointReturns500WhenUnexpectedExceptionOccurs(): void
    {
        Exceptions::fake();

        $user = User::factory()->create();
        $options = $this->startCeremony($user);

        // Die Zeremonie läuft vollständig durch; erst das Speichern trifft die
        // fehlende Tabelle und erzeugt so den Fehler unterhalb der Zeremonie.
        Schema::drop('passkey_credentials');

        $response = $this->actingAs($user)->postJson(
            self::REGISTER_URL,
            VirtualAuthenticator::create()->attestation($options),
            ['Content-Type' => self::CONTENT_TYPE_JSON],
        );

        $response->assertInternalServerError();
        Exceptions::assertReported(QueryException::class);

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
        $options = $this->startCeremony($user);

        $this->actingAs($user)->postJson(
            self::REGISTER_URL,
            VirtualAuthenticator::create()->attestation($options),
            ['Content-Type' => self::CONTENT_TYPE_JSON],
        )->assertCreated();

        $credential = PasskeyCredential::query()->where('user_id', $user->getKey())->sole();

        $this->assertSame(__('app.passkey_default_name'), $credential->name);
    }

    public function testStoreEndpointUsesDefaultNameWhenNameIsBlank(): void
    {
        $user = User::factory()->create();
        $options = $this->startCeremony($user);

        $this->actingAs($user)->postJson(
            self::REGISTER_URL,
            [...VirtualAuthenticator::create()->attestation($options), 'name' => '   '],
            ['Content-Type' => self::CONTENT_TYPE_JSON],
        )->assertCreated();

        $credential = PasskeyCredential::query()->where('user_id', $user->getKey())->sole();

        $this->assertSame(__('app.passkey_default_name'), $credential->name);
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

    /**
     * Ruft den Options-Endpunkt auf und liefert die Optionen zurück, die der
     * Server sich für den folgenden POST gemerkt hat.
     */
    private function startCeremony(User $user): PublicKeyCredentialCreationOptions
    {
        $this->actingAs($user)->getJson(self::REGISTER_OPTIONS_URL)->assertOk();

        $stored = session(self::SESSION_KEY);
        $json = is_array($stored) && is_string($stored['data'] ?? null)
            ? $stored['data']
            : throw new \RuntimeException('Der Options-Endpunkt hat nichts in der Session hinterlegt.');

        return app(SerializerInterface::class)->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }
}
