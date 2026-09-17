<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
    private const string PROFILE_INFORMATION_URL = '/user/profile-information';
    private const string PASSWORD_URL = '/user/password';
    private const string PROFILE_URL = '/user/profile';

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
            ->test(PasskeyManagerForm::class)
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

    /**
     * Der Durchstich am ersten der beiden Formulare, die statt einer bestätigten
     * Sitzung bisher das Passwort verlangten: Ohne nutzbares Passwort war die
     * eigene Adresse nicht mehr erreichbar.
     */
    public function testAPasskeyConfirmedSessionAuthorizesTheEmailChange(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]);

        $this->actingAs($user);
        $this->confirmWithPasskey($user);

        $this->put(self::PROFILE_INFORMATION_URL, [
            'name' => $user->name,
            'email' => 'neu@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
    }

    /**
     * Dasselbe am zweiten Formular. Ohne diesen Weg bliebe ein abgephishtes
     * Passwort unveränderlich: Der Link zum Zurücksetzen wird an diesen Konten
     * nicht mehr verschickt.
     */
    public function testAPasskeyConfirmedSessionAuthorizesThePasswordChange(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->actingAs($user);
        $this->confirmWithPasskey($user);

        $this->put(self::PASSWORD_URL, [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('brand-new-password', $refreshedUser->password));
    }

    /**
     * Die Bestätigung tritt an die Stelle des Passworts, sie fällt nicht weg:
     * Eine gekaperte Sitzung ohne Passkey kommt an die Kontoadresse so wenig
     * heran wie zuvor mit dem abgephishten Passwort.
     */
    public function testTheEmailChangeStaysClosedWithoutAConfirmation(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]);

        $this->actingAs($user)->put(self::PROFILE_INFORMATION_URL, [
            'name' => $user->name,
            'email' => 'neu@example.com',
        ])->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');

        $this->assertNull($user->fresh()?->pending_email);
        Notification::assertNothingSent();
    }

    public function testThePasswordChangeStaysClosedWithoutAConfirmation(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->actingAs($user)->put(self::PASSWORD_URL, [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertFalse(Hash::check('brand-new-password', $refreshedUser->password));
    }

    /**
     * Getragen wird die Änderung von der Bestätigung; ein daneben gesendetes
     * Passwort fügt ihr nichts hinzu und nimmt ihr nichts. Bliebe sie daran
     * hängen, träfe das den Eigentümer: Sein Passwortfeld steht nach dem
     * Abschalten noch im alten Seitenaufbau, und sein Inhalt ginge als
     * abgephishtes Passwort ins Audit-Log. Ohne Bestätigung bleibt der Weg zu,
     * mit Passwort so gut wie ohne.
     */
    public function testASubmittedPasswordDoesNotBlockTheConfirmedChange(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->actingAs($user);
        $this->confirmWithPasskey($user);
        Activity::query()->delete();

        $this->put(self::PASSWORD_URL, [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertTrue(Hash::check('brand-new-password', $refreshedUser->password));
        $this->assertNull(Activity::query()->where('event', 'password_update_failed')->first());
    }

    /** Der Livewire-Pfad ruft die Action direkt auf, an den Routen vorbei. */
    public function testTheLivewireProfileFormAlsoAcceptsAPasskeyConfirmedSession(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]);

        $this->actingAs($user);
        $this->confirmWithPasskey($user);

        Livewire::actingAs($user)
            ->test(UpdateProfileInformationForm::class)
            ->set('state.name', $user->name)
            ->set('state.email', 'neu@example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
    }

    /**
     * Der Hinweis unter dem Knopf verspricht die Passkey-Bestätigung für
     * Änderungen auf dieser Seite. Die beiden Formulare mit eigenem
     * Passwortfeld hielten dieses Versprechen zuletzt nicht.
     */
    public function testTheProfilePageReplacesBothPasswordFieldsWithThePasskeyWay(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(self::PROFILE_URL);

        $response->assertOk();
        $response->assertDontSee('id="current_password"', false);
        $response->assertDontSee('id="email-change-current-password"', false);
        $response->assertSee(__('app.password_change_passkey_hint'));
        $response->assertSee(__('app.email_change_passkey_hint'));
        $response->assertSee('id="confirm-password-update-password"', false);
        $response->assertSee('id="confirm-password-update-profile-information"', false);
    }

    /** Regression: Konten mit offenem Passwort-Login behalten ihre Passwortfelder. */
    public function testTheProfilePageKeepsBothPasswordFieldsWithoutTheSwitch(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(self::PROFILE_URL);

        $response->assertOk();
        $response->assertSee('id="current_password"', false);
        $response->assertSee('id="email-change-current-password"', false);
        $response->assertDontSee('id="confirm-password-update-password"', false);
    }

    /**
     * Der Vergleichswert entscheidet, ob der Speichern-Knopf den Nachweis
     * verlangt. In den Attributen einer Blade-Komponente bleibt ein
     * `@js()`-Aufruf unausgewertet stehen und ergäbe erst im Browser einen
     * Syntaxfehler — serverseitig sieht die Seite dann unauffällig aus.
     */
    public function testTheComparisonEmailReachesAlpineAsAValue(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->get(self::PROFILE_URL)
            ->assertSee("originalEmail: 'original@example.com'", false);
    }

    /**
     * Enter im Textfeld klickt den ersten Submit-Knopf des Formulars — auch
     * einen ausgeblendeten, denn das `display:none` von `x-show` ändert nichts
     * an der Dokumentreihenfolge. Ohne den Griff am Formular liefe die
     * E-Mail-Änderung am Passkey-Dialog vorbei und endete an einer Meldung, die
     * eine Bestätigung verlangt, die die Seite in dem Moment nicht anbietet.
     */
    public function testEnterOnTheProfileFormLandsInThePasskeyDialog(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(self::PROFILE_URL);

        $response->assertOk();
        $response->assertSee('x-on:submit.capture=', false);
        $response->assertSee('x-ref="emailChangeSave"', false);
        $response->assertSee("\$refs.emailChangeSave.querySelector('button').click()", false);
    }

    /**
     * Regression: Der Griff steht im Markup beider Varianten und erkennt am
     * fehlenden Knopf, dass es hier nichts zu bestätigen gibt — mit sichtbarem
     * Passwortfeld bleibt Enter das gewohnte Absenden.
     */
    public function testEnterKeepsSubmittingWhereThePasswordLoginIsOpen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(self::PROFILE_URL)
            ->assertDontSee('x-ref="emailChangeSave"', false);
    }

    /**
     * Enter in einem Feld klickt den ersten Submit-Knopf des Formulars — und
     * ohne einen solchen sendet der Browser bei zwei Passwortfeldern gar nichts
     * ab. Der Speichern-Knopf des Passkey-Zweigs muss deshalb ein Submit-Knopf
     * sein, dessen abgefangener Klick nur den Dialog öffnet: So nimmt Enter
     * denselben Weg wie der Mausklick, statt zu verhallen.
     */
    public function testEnterOnThePasswordFormLandsInThePasskeyDialog(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(self::PROFILE_URL)->content();
        $saveButton = Str::between($html, 'wire:then="updatePassword"', 'id="confirm-password-update-password"');

        $this->assertStringContainsString('type="submit"', $saveButton);
        $this->assertStringContainsString('x-on:click.prevent=""', $saveButton);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('passkey-confirm');
        RateLimiter::clear('passkey-register');
    }

    /**
     * Legt eine per Passkey bestätigte Sitzung an — der einzige Weg zu ihr,
     * solange der abgeschaltete Passwort-Login dem Passwort die Wirkung nimmt.
     */
    private function confirmWithPasskey(User $user): void
    {
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

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
    }
}
