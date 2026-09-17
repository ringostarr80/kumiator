<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Deckt ab, dass der abgeschaltete Passwort-Login dem Passwort auch als
 * Bestätigungsfaktor die Wirkung nimmt — und dass an seiner Stelle der Passkey
 * tritt.
 *
 * Ohne das bliebe das Passwort der Schlüssel zur Passkey-Verwaltung: Wer es
 * abgephisht hat und an eine Sitzung kommt, stünde vor derselben Hürde wie vor
 * dem Abschalten.
 */
final class PasskeyConfirmationGateTest extends TestCase
{
    use RefreshDatabase;

    private const string CONFIRM_PASSWORD_URL = '/user/confirm-password';
    private const string CONFIRM_OPTIONS_URL = '/user/passkeys/confirm/options';
    private const string CONFIRM_URL = '/user/passkeys/confirm';
    private const string REGISTER_OPTIONS_URL = '/user/passkeys/register/options';

    public function testTheCorrectPasswordNoLongerConfirmsOnceTheSwitchIsSet(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        // Das Passwort selbst bleibt unverändert gültig — nur zählt es hier nicht mehr.
        $this->assertTrue(Hash::check('password', $user->password));

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL, ['password' => 'password']);

        $response->assertSessionHasErrors();
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function testTheRejectedPasswordConfirmationNamesItsReason(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL, ['password' => 'password']);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_confirmation_failed')
            ->where('causer_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('password_login_disabled', $activity->properties?->get('failure_reason'));
    }

    /**
     * `password_login_disabled` belegt in den Regelketten und im Login-Pfad ein
     * genanntes Passwort. Zöge diese Prüfung hier vor den Hash-Vergleich, trüge
     * diese Aussage auch jeder blinde Rateversuch gegen ein abgeschaltetes Konto —
     * und das Raten verlöre seinen eigenen Grund an genau den Konten, an denen er
     * zählt.
     */
    public function testAWrongPasswordAtTheConfirmationIsAuditedAsAMismatch(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL, ['password' => 'wrong-password']);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_confirmation_failed')
            ->where('causer_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('current_password_mismatch', $activity->properties?->get('failure_reason'));
    }

    /**
     * Der Livewire-Pfad läuft über dieselbe Fortify-Action wie der Formular-Pfad;
     * bräche die Sperre dort, stünde die Passkey-Verwaltung dem Passwort weiter
     * offen.
     */
    public function testTheLivewireDialogAlsoRefusesThePassword(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->assertHasErrors('confirmable_password');

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    /**
     * Regression: Konten mit offenem Passwort-Login behalten den Passwort-Weg. Die
     * Sperre darf nicht auf den Normalfall durchschlagen.
     */
    public function testAnAccountWithoutTheSwitchStillConfirmsWithItsPassword(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL, ['password' => 'password']);

        $response->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, session('auth.password_confirmed_at'));
    }

    /**
     * Der Durchstich: Ein Konto ohne nutzbares Passwort weist seine Sitzung per
     * Assertion nach und kommt damit an die Verwaltung, die `password.confirm`
     * schützt.
     */
    public function testAPasskeyConfirmationOpensThePasskeyManagement(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);

        // Ohne Bestätigung bleibt die Verwaltung zu.
        $this->getJson(self::REGISTER_OPTIONS_URL)->assertStatus(423);

        $content = $this->getJson(self::CONFIRM_OPTIONS_URL)->content();
        $options = app(SerializerInterface::class)
            ->deserialize($content, PublicKeyCredentialRequestOptions::class, 'json');

        $this->call(
            'POST',
            self::CONFIRM_URL,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $authenticator->signAssertion($credential, $options),
        )->assertOk();

        $this->getJson(self::REGISTER_OPTIONS_URL)->assertOk();
    }

    /**
     * Die Bestätigungs-Endpunkte sind der Weg, die Bestätigung zu erlangen. Lägen
     * sie selbst hinter `password.confirm`, käme ein Konto ohne nutzbares Passwort
     * nie an sie heran.
     */
    public function testTheConfirmationEndpointsAreReachableWithoutAConfirmedSession(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user)->getJson(self::CONFIRM_OPTIONS_URL)->assertOk();
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('passkey-confirm');
        RateLimiter::clear('passkey-register');
    }
}
