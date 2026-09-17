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

final class PasskeyConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const string CONFIRM_OPTIONS_URL = '/user/passkeys/confirm/options';
    private const string CONFIRM_URL = '/user/passkeys/confirm';
    private const string LIBRARY_UV_REJECTION = 'User authentication required.';
    private const string LIBRARY_ID_REJECTION = 'The credential ID is not allowed.';

    // ──────────────────────────────────────────────────────────────────────────
    // Options endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testOptionsEndpointRejectsGuests(): void
    {
        $this->getJson(self::CONFIRM_OPTIONS_URL)->assertUnauthorized();
    }

    /**
     * Der Aufrufer ist bereits angemeldet — die Liste verrät ihm nur, was er über
     * sein eigenes Konto ohnehin weiß, und erspart ihm die Auswahl aus allen für
     * die Domain gespeicherten Passkeys.
     */
    public function testOptionsEndpointListsOnlyTheOwnCredentials(): void
    {
        $user = User::factory()->create();
        $own = PasskeyCredential::factory()->for($user)->create();
        PasskeyCredential::factory()->for(User::factory()->create())->create();

        $response = $this->actingAs($user)->getJson(self::CONFIRM_OPTIONS_URL);

        $response->assertOk();
        $response->assertJsonCount(1, 'allowCredentials');
        $response->assertJsonPath('allowCredentials.0.id', $own->credential_id);
    }

    /**
     * Ohne `required` sähe die Bibliothek das UV-Flag nicht an, und der Besitz des
     * Authenticators allein bestätigte die Sitzung.
     */
    public function testOptionsEndpointDemandsUserVerification(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson(self::CONFIRM_OPTIONS_URL);

        $response->assertJsonPath(
            'userVerification',
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );
    }

    /**
     * Ohne eigenen Passkey bliebe `allowCredentials` leer, und genau dann
     * überspringt die Bibliothek ihre Prüfung: Der Browser böte jeden für die
     * Domain gespeicherten Passkey an, und die Zeremonie liefe bis zur
     * Signaturprüfung gegen ein fremdes Credential, ehe der Eigentümervergleich
     * sie abweist. Ein Konto mit eigenen Passkeys ist davor geschützt — dieses
     * fiele als einziges aus dem Schutz heraus.
     */
    public function testOptionsEndpointRefusesAnAccountWithoutAPasskey(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for(User::factory()->create())->create();

        $this->actingAs($user)->getJson(self::CONFIRM_OPTIONS_URL)->assertUnprocessable();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Confirm endpoint
    // ──────────────────────────────────────────────────────────────────────────

    public function testConfirmEndpointRejectsGuests(): void
    {
        $this->postJson(self::CONFIRM_URL, [])->assertUnauthorized();
    }

    public function testConfirmReturns422WhenSessionIsMissing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertUnprocessable();
    }

    public function testConfirmReturns400WhenRequestBodyIsEmpty(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user)->getJson(self::CONFIRM_OPTIONS_URL);

        $this->call(
            'POST',
            self::CONFIRM_URL,
            server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: '',
        )->assertBadRequest();
    }

    /**
     * Kern des Bausteins: Eine geprüfte Assertion setzt denselben Session-Anker,
     * den `RequirePassword` und Jetstreams `ConfirmsPasswords` lesen.
     */
    public function testAValidAssertionConfirmsTheSession(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $options = $this->requestOptions();

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

        $response->assertOk();
        $response->assertJsonStructure(['redirect']);
        $this->assertGreaterThan(0, session('auth.password_confirmed_at'));
    }

    /**
     * Das gemerkte Ziel gehört der Seite, die dorthin wollte: `intended()` zieht es
     * aus der Sitzung, und der Dialog im Profil bräuchte es nie. Nähme er es mit,
     * landete die noch wartende Bestätigungsseite später auf der Startseite.
     */
    public function testTheDialogVariantLeavesTheRememberedTargetInTheSession(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $target = url('/teams/create');
        session(['url.intended' => $target]);
        $options = $this->requestOptions();

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

        $response->assertOk();
        $this->assertSame($target, session('url.intended'));
    }

    /** Die Vollseiten-Variante ist die, für die das Ziel hinterlegt wurde — sie fordert es an und verbraucht es. */
    public function testThePageVariantReceivesTheRememberedTargetAndConsumesIt(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $target = url('/teams/create');
        session(['url.intended' => $target]);
        $options = $this->requestOptions();

        $response = $this->postAssertion(
            $authenticator->signAssertion($credential, $options),
            wantsRememberedTarget: true,
        );

        $response->assertJsonPath('redirect', $target);
        $this->assertNull(session('url.intended'));
    }

    public function testASuccessfulConfirmationIsAudited(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $this->postAssertion($authenticator->signAssertion($credential, $this->requestOptions()))->assertOk();

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'passkey_confirmation_succeeded')
                ->where('causer_id', $user->id)
                ->count(),
        );
    }

    /**
     * Ein fremder Passkey darf die Sitzung nicht bestätigen — sonst öffnete er die
     * Verwaltung eines Kontos, zu dem er nicht gehört. Die Zeremonie weist ihn an
     * ihrer Liste zugelassener Credentials ab, der Eigentümervergleich dahinter
     * fängt, was sie durchließe.
     */
    public function testAnotherUsersPasskeyCannotConfirmTheSession(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        // Ohne eigenen Passkey verweigert der Options-Endpunkt die Challenge, und
        // der POST endete an der leeren Sitzung, ehe der Vergleich überhaupt fällt.
        $authenticator->registerFor($user);
        $strangersCredential = $authenticator->registerFor($stranger);

        $this->actingAs($user);
        $options = $this->requestOptions();
        $untouched = $strangersCredential->refresh()->getAttributes();

        $response = $this->postAssertion($authenticator->signAssertion($strangersCredential, $options));

        $response->assertUnprocessable();
        $this->assertNull(session('auth.password_confirmed_at'));

        // Die Ablehnung darf am fremden Datensatz nichts hinterlassen: `last_used_at`
        // steht in der Verwaltung seines Eigentümers und belegte dort eine Nutzung,
        // die zu keiner Bestätigung geführt hat.
        $this->assertSame($untouched, $strangersCredential->refresh()->getAttributes());
    }

    /**
     * Abgewiesen wird der fremde Passkey ohnehin. Ohne eigenen Grund sähe der Versuch
     * im Log aber aus wie ein abgebrochener Dialog, statt festzuhalten, dass hier die
     * Credential-ID eines anderen Kontos gegen diese Sitzung stand. Der Grund ersetzt
     * den Wortlaut der Bibliothek nicht — nur der sagt, an welchem Schritt sie brach.
     */
    public function testAForeignPasskeyIsAuditedAsAnOwnerMismatch(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $authenticator->registerFor($user);
        $strangersCredential = $authenticator->registerFor($stranger);

        $this->actingAs($user);

        $this->postAssertion($authenticator->signAssertion($strangersCredential, $this->requestOptions()))
            ->assertUnprocessable();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_confirmation_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('credential_owner_mismatch', $activity->properties?->get('failure_reason'));
        $this->assertSame(self::LIBRARY_ID_REJECTION, $activity->properties->get('failure_detail'));
    }

    public function testAnAssertionWithoutUserVerificationDoesNotConfirm(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $options = $this->requestOptions();

        $response = $this->postAssertion(
            $authenticator->signAssertion($credential, $options, userVerified: false),
        );

        $response->assertUnprocessable();
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    /**
     * Mit gefüllter `allowCredentials` stand der Nutzer schon vor der Zeremonie
     * fest, und der Authenticator darf den Handle weglassen. Verlangte die
     * Bestätigung ihn trotzdem, spräche sie einem Konto ohne nutzbares Passwort
     * jeden Weg zurück in seine Verwaltung ab.
     */
    public function testAnAssertionWithoutUserHandleConfirmsTheSession(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $options = $this->requestOptions();

        $response = $this->postAssertion(
            $authenticator->signAssertion($credential, $options, withUserHandle: false),
        );

        $response->assertOk();
        $this->assertGreaterThan(0, session('auth.password_confirmed_at'));
    }

    public function testAFailedConfirmationIsAudited(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $options = $this->requestOptions();

        // Ein fremder Authenticator signiert für einen Passkey, der ihm nicht gehört.
        $this->postAssertion(VirtualAuthenticator::create()->signAssertion($credential, $options))
            ->assertUnprocessable();

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'passkey_confirmation_failed')
                ->where('causer_id', $user->id)
                ->count(),
        );
    }

    /**
     * Die Bestätigung ist die einzige Hürde vor den Sicherheitseinstellungen eines
     * Kontos ohne Passwort-Login. Scheitert ein Authenticator dort immer wieder,
     * muss das Log sagen, an welchem Schritt — die Antwort verrät es absichtlich
     * nicht, und eine Serie gleicher Einträge sagt es auch nicht.
     */
    public function testAFailedConfirmationKeepsTheLibraryWordingInTheLog(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);

        $response = $this->postAssertion(
            $authenticator->signAssertion($credential, $this->requestOptions(), userVerified: false),
        );

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_confirmation_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(self::LIBRARY_UV_REJECTION, $activity->properties?->get('failure_detail'));
        $this->assertStringNotContainsString(self::LIBRARY_UV_REJECTION, $response->content());
    }

    /**
     * `CheckCounter` steht am Ende der Zeremonie, hinter der Signaturprüfung: Wer
     * dort scheitert, hat mit dem echten Schlüssel signiert und meldet trotzdem
     * einen bereits gesehenen Zählerstand — der Befund für einen geklonten
     * Authenticator. Das stärkste Signal, das die Zeremonie kennt, darf nicht als
     * Serverfehler enden, den kein Eintrag festhält.
     */
    public function testAStagnatingSignatureCounterIsRejectedAndAudited(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);

        $this->postAssertion($authenticator->signAssertion($credential, $this->requestOptions(), counter: 5))
            ->assertOk();

        // Derselbe Zählerstand ein zweites Mal: der Mitschnitt eines Klons.
        $this->postAssertion($authenticator->signAssertion($credential, $this->requestOptions(), counter: 5))
            ->assertUnprocessable();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_confirmation_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('counter_invalid', $activity->properties?->get('failure_reason'));
    }

    /**
     * Die Bestätigung steht nach `passwordConfirmed()` bereits in der Sitzung.
     * Bräche der Audit-Insert danach durch, verkleidete ein 500er eine
     * abgeschlossene Bestätigung als Fehlschlag.
     */
    public function testASuccessfulConfirmationSurvivesFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);
        $options = $this->requestOptions();

        Schema::drop('activity_log');

        $response = $this->postAssertion($authenticator->signAssertion($credential, $options));

        $response->assertOk();
        $this->assertGreaterThan(0, session('auth.password_confirmed_at'));
        Exceptions::assertReported(QueryException::class);
    }

    public function testConfirmReturns500WhenUnexpectedExceptionOccurs(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user);
        $this->getJson(self::CONFIRM_OPTIONS_URL);

        $this->partialMock(PasskeyAuthenticationContract::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->andThrow(new \RuntimeException('Unexpected error.'));
        });

        $this->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertInternalServerError();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_confirmation_failed')
            ->where('causer_id', $user->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('internal_error', $activity->properties?->get('failure_reason'));
    }

    /**
     * Zugesagt sind zehn Bestätigungsversuche. Im Browser besteht jeder davon aus
     * zwei Aufrufen — erst die Optionen, dann die Assertion. Zählen beide auf
     * denselben Zähler, bleiben davon fünf, und wer seinen Authenticator ein
     * paarmal verpatzt, steht vor einem 429 statt vor dem nächsten Versuch.
     */
    public function testTenFailedAttemptsFitIntoTheLimitAlthoughEachFetchesOptions(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);

        // Eine Assertion ohne Nutzerverifikation ist der verpatzte Versuch: Der
        // Authenticator antwortet, der Server weist ab, und beide Aufrufe zählen.
        for ($i = 0; $i < 10; $i++) {
            $this->postAssertion(
                $authenticator->signAssertion($credential, $this->requestOptions(), userVerified: false),
            )->assertUnprocessable();
        }

        $this->postAssertion(
            $authenticator->signAssertion($credential, $this->requestOptions()),
        )->assertTooManyRequests();
    }

    public function testConfirmEndpointIsRateLimited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertUnprocessable();
        }

        $this->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertTooManyRequests();
    }

    /**
     * Bestätigt wird eine angemeldete Sitzung, deshalb zählt das Konto und nicht
     * die Adresse: Ein Kollege hinter demselben Netzübergang, der seine zehn
     * Versuche verbraucht, sperrte sonst alle anderen mit.
     */
    public function testTheLimitCountsPerAccountNotPerAddress(): void
    {
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertUnprocessable();
        }

        $this->actingAs(User::factory()->create());

        $this->postJson(self::CONFIRM_URL, ['data' => 'test'])->assertUnprocessable();
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('passkey-confirm');
    }

    /**
     * Holt die Optionen über den echten Endpunkt — nur so landet die Challenge in
     * der Session, gegen die der spätere POST geprüft wird.
     */
    private function requestOptions(): PublicKeyCredentialRequestOptions
    {
        $content = $this->getJson(self::CONFIRM_OPTIONS_URL)->content();

        return app(SerializerInterface::class)->deserialize($content, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * Sendet die Assertion als rohen JSON-Body, wie es der Browser tut.
     *
     * @param bool $wantsRememberedTarget Ruft auf, wie es die Vollseiten-Variante tut.
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function postAssertion(string $rawResponse, bool $wantsRememberedTarget = false): TestResponse
    {
        return $this->call(
            'POST',
            self::CONFIRM_URL . ($wantsRememberedTarget ? '?intended=1' : ''),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $rawResponse,
        );
    }
}
