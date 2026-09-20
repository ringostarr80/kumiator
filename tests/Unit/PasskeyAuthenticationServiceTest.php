<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\WebauthnConfig;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\Exceptions\CredentialOwnerMismatchException;
use App\Services\WebAuthn\PasskeyAuthenticationService;
use App\Services\WebAuthn\PasskeyLoginContext;
use App\Services\WebAuthn\WebAuthnValidatorFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\Support\RecordingValidatorFactory;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorData;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\InvalidUserHandleException;
use Webauthn\PublicKeyCredentialRequestOptions;

final class PasskeyAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyAuthenticationService $service;

    public function testCreateOptionsReturnsEmptyAllowCredentialsEvenWhenPasskeysExist(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        $options = $this->service->createOptions();

        $this->assertInstanceOf(PublicKeyCredentialRequestOptions::class, $options);
        $this->assertEmpty($options->allowCredentials);
    }

    public function testCreateOptionsGeneratesNonEmptyChallenge(): void
    {
        $options = $this->service->createOptions();

        $this->assertNotEmpty($options->challenge);
        $this->assertGreaterThanOrEqual(16, strlen($options->challenge));
    }

    public function testCreateOptionsRequiresUserVerification(): void
    {
        $options = $this->service->createOptions();

        $this->assertSame(
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $options->userVerification,
        );
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
        // 32 bytes RP-ID hash | 1 byte flags (UP+UV) | 4 bytes sign counter (big-endian)
        $authenticatorData = str_repeat("\x00", 32)
            . chr(AuthenticatorData::FLAG_UP | AuthenticatorData::FLAG_UV)
            . "\x00\x00\x00\x01";

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
        $this->expectExceptionMessageIs('credential_not_found');

        $this->service->verify($rawResponse, $options, 'localhost');
    }

    public function testVerifyAcceptsAGenuineAssertionAndPersistsTheNewCounter(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createOptions();

        $verified = $this->service->verify(
            $authenticator->signAssertion($credential, $options, counter: 7),
            $options,
            WebauthnConfig::effectiveHost(),
        );

        $this->assertSame($credential->id, $verified->id);
        $this->assertSame(7, $credential->refresh()->counter);
    }

    /**
     * Maßstab ist der Handle im gespeicherten CredentialRecord, nicht der am
     * Nutzer: Weichen beide ab, bleibt die Assertion gültig, solange der
     * Authenticator den Handle des Records sendet.
     */
    public function testVerifyAcceptsACredentialWhoseRecordHandleDiffersFromTheUsers(): void
    {
        $user = User::factory()->create();
        $recordHandle = (string) $user->id;
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user, $recordHandle);
        $options = $this->service->createOptions();

        $verified = $this->service->verify(
            $authenticator->signAssertion($credential, $options),
            $options,
            WebauthnConfig::effectiveHost(),
        );

        $this->assertSame($credential->id, $verified->id);
    }

    public function testVerifyRejectsAnAssertionSignedByAForeignKey(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createOptions();

        // Zweiter Authenticator signiert für den Passkey des ersten.
        $impostor = VirtualAuthenticator::create();

        // Der Wortlaut der Bibliothek ist hier die einzige Zusicherung, dass die
        // Zeremonie erst an der Signaturprüfung scheitert und nicht schon vorher.
        $this->expectException(AuthenticatorResponseVerificationException::class);
        $this->expectExceptionMessageIs('Invalid signature.');

        $this->service->verify(
            $impostor->signAssertion($credential, $options),
            $options,
            WebauthnConfig::effectiveHost(),
        );
    }

    public function testVerifyRejectsAnAssertionWithoutUserVerification(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createOptions();

        // Signatur und Zähler stimmen; abgewiesen wird allein, weil der
        // Authenticator den Nutzer nicht verifiziert hat.
        $this->expectException(AuthenticatorResponseVerificationException::class);
        $this->expectExceptionMessageIs('User authentication required.');

        $this->service->verify(
            $authenticator->signAssertion($credential, $options, userVerified: false),
            $options,
            WebauthnConfig::effectiveHost(),
        );
    }

    public function testVerifyRejectsAnAssertionWithoutUserHandle(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createOptions();

        // Ohne `allowCredentials` verlangt die Spezifikation einen User-Handle in
        // der Antwort (WebAuthn L3, §7.2 Schritt 6).
        $this->expectException(InvalidUserHandleException::class);

        $this->service->verify(
            $authenticator->signAssertion($credential, $options, withUserHandle: false),
            $options,
            WebauthnConfig::effectiveHost(),
        );
    }

    public function testVerifyRejectsAnAssertionWithAForeignUserHandle(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createOptions();

        $this->expectException(InvalidUserHandleException::class);

        $this->service->verify(
            $authenticator->signAssertion($credential, $options, userHandleOverride: 'someone-else'),
            $options,
            WebauthnConfig::effectiveHost(),
        );
    }

    /**
     * Der eingesetzte Handle stammt vom angemeldeten Konto, nicht aus dem
     * gespeicherten Record: Sonst vergliche `CheckUserHandle` den Record gegen
     * sich selbst, und von der Prüfung bliebe nur die Fassade. Woran die Zeremonie
     * scheiterte, hängt als Ursache an der Ablehnung — benannt ist sie nach dem
     * fremden Konto, das die Antwort vorgelegt hat.
     */
    public function testTheSubstitutedUserHandleBelongsToTheIdentifiedUser(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $strangersCredential = $authenticator->registerFor($stranger);
        $options = $this->service->createConfirmationOptions($stranger);

        try {
            $this->service->verify(
                $authenticator->signAssertion($strangersCredential, $options, withUserHandle: false),
                $options,
                WebauthnConfig::effectiveHost(),
                identifiedUser: $user,
            );
            $this->fail('Der Handle des angemeldeten Kontos hätte nicht zum fremden Record passen dürfen.');
        } catch (CredentialOwnerMismatchException $e) {
            $this->assertInstanceOf(InvalidUserHandleException::class, $e->getPrevious());
        }
    }

    /**
     * Die Bibliothek wertet einen leeren Handle in der Antwort wie einen
     * fehlenden; der Dienst muss das ebenso tun, sonst tritt `''` an die Stelle
     * des Konto-Handles und der eigene Passkey scheitert am Vergleich.
     */
    public function testAnEmptyUserHandleCountsAsAbsentForTheIdentifiedUser(): void
    {
        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $options = $this->service->createConfirmationOptions($user);

        $verified = $this->service->verify(
            // `userHandleOverride: ''` landet als `"userHandle": ""` in der Antwort.
            $authenticator->signAssertion($credential, $options, userHandleOverride: ''),
            $options,
            WebauthnConfig::effectiveHost(),
            identifiedUser: $user,
        );

        $this->assertTrue($verified->is($credential));
    }

    /**
     * Steht der Eigentümer nicht zur Sitzung, bricht die Zeremonie ab, bevor sie
     * den Datensatz anfasst: Zähler und `last_used_at` stünden sonst in der
     * Passkey-Verwaltung des Eigentümers für eine Nutzung, die zu keiner
     * Bestätigung geführt hat.
     */
    public function testAForeignCredentialIsRejectedWithoutTouchingItsRecord(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $strangersCredential = $authenticator->registerFor($stranger);

        // Ohne eigenen Passkey bleibt `allowCredentials` leer, und die Zeremonie
        // lässt ihre Credential-Prüfung dann aus. Über den Endpunkt ist dieser
        // Zustand nicht erreichbar; direkt am Service gestellt, zeigt er, dass
        // der Eigentümervergleich auch ohne die Liste trägt.
        $options = $this->service->createConfirmationOptions($user);
        $untouched = $strangersCredential->refresh()->getAttributes();

        try {
            $this->service->verify(
                $authenticator->signAssertion($strangersCredential, $options),
                $options,
                WebauthnConfig::effectiveHost(),
                identifiedUser: $user,
            );
            $this->fail('Der Passkey eines anderen Kontos hätte abgelehnt werden müssen.');
        } catch (AuthenticatorResponseVerificationException $e) {
            $this->assertSame('credential_owner_mismatch', $e->getMessage());
        }

        $this->assertSame($untouched, $strangersCredential->refresh()->getAttributes());
    }

    public function testFakeVerificationFailsAtTheSameStepAsAGenuineSignatureMismatch(): void
    {
        $factory = new RecordingValidatorFactory();
        $service = new PasskeyAuthenticationService(
            $factory,
            new PasskeyCredentialRepository(),
            app(SerializerInterface::class),
            new PasskeyLoginContext(),
        );

        $user = User::factory()->create();
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);
        $host = WebauthnConfig::effectiveHost();

        // Referenz: bekanntes Credential, fremd signiert — scheitert an der Signaturprüfung.
        $options = $service->createOptions();
        $impostor = VirtualAuthenticator::create();

        try {
            $service->verify($impostor->signAssertion($credential, $options), $options, $host);
            $this->fail('Die fremd signierte Assertion hätte abgelehnt werden müssen.');
        } catch (AuthenticatorResponseVerificationException) {
            // erwartet
        }

        // Fake-Pfad: dieselbe Assertion, aber das Credential existiert nicht mehr.
        $options = $service->createOptions();
        $rawResponse = $authenticator->signAssertion($credential, $options);
        $credential->deleteOrFail();

        try {
            $service->verify($rawResponse, $options, $host);
            $this->fail('Eine unbekannte Credential-ID hätte abgelehnt werden müssen.');
        } catch (AuthenticatorResponseVerificationException) {
            // erwartet
        }

        $calls = $factory->recordedCalls();

        $this->assertCount(2, $calls);
        $this->assertInstanceOf(\Throwable::class, $calls[0]['error']);
        $this->assertInstanceOf(\Throwable::class, $calls[1]['error']);

        // Gleiche Fehlermeldung heißt: gleicher Zeremonie-Schritt, also vergleichbare
        // Laufzeit. Verglichen werden zwei Laufzeitwerte, kein festgeschriebener Text.
        $this->assertSame($calls[0]['error']->getMessage(), $calls[1]['error']->getMessage());
    }

    public function testCreateOptionsGeneratesDifferentChallengesOnEachCall(): void
    {
        $optionsA = $this->service->createOptions();
        $optionsB = $this->service->createOptions();

        $this->assertNotSame($optionsA->challenge, $optionsB->challenge);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $serializer = app(SerializerInterface::class);

        $this->service = new PasskeyAuthenticationService(
            new WebAuthnValidatorFactory(app(AttestationStatementSupportManager::class)),
            new PasskeyCredentialRepository(),
            $serializer,
            new PasskeyLoginContext(),
        );
    }
}
