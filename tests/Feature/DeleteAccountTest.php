<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\DeleteUserForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Jetstream\Features;
use Livewire\Livewire;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\Support\ConfirmsPassword;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialRequestOptions;

final class DeleteAccountTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    private const string CONFIRM_OPTIONS_URL = '/user/passkeys/confirm/options';
    private const string CONFIRM_URL = '/user/passkeys/confirm';

    public function testUserAccountsCanBeDeleted(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->call('deleteUser');

        $this->assertNull($user->fresh());
    }

    public function testCorrectPasswordMustBeProvidedBeforeAccountCanBeDeleted(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->set('confirmablePassword', 'wrong-password')
            ->call('confirmPassword')
            ->assertHasErrors(['confirmable_password']);

        $this->assertNotNull($user->fresh());
    }

    /**
     * Der abgeschaltete Passwort-Login nimmt dem Passwort seine Autorität —
     * die Kontolöschung ist ein Hard-Delete und damit die folgenreichste
     * Aktion, die hinter dieser Hürde steht.
     */
    public function testAccountCannotBeDeletedWithThePasswordWhenPasswordLoginIsDisabled(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->actingAs($user = User::factory()->create([
            'password_login_disabled_at' => now(),
        ]));

        Livewire::test(DeleteUserForm::class)
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->assertHasErrors(['confirmable_password']);

        $this->assertNotNull($user->fresh());
    }

    /**
     * Die Bestätigung ist die einzige Hürde vor der Löschung — ein Aufruf, der
     * den Dialog übergeht, darf sie nicht überspringen können.
     */
    public function testAccountCannotBeDeletedWithoutAConfirmedSession(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->call('deleteUser')
            ->assertForbidden();

        $this->assertNotNull($user->fresh());
    }

    /**
     * Die Löschung lässt sich nicht zurücknehmen und verlangt deshalb einen
     * Nachweis aus diesem Vorgang — nicht die Bestätigung von vor Stunden, mit
     * der die übrigen Profilbereiche auskommen.
     */
    public function testAnAgedConfirmationNoLongerReachesTheDeletion(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->confirmPassword(600);
        $this->actingAs($user = User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->call('deleteUser')
            ->assertForbidden();

        $this->assertNotNull($user->fresh());
    }

    /**
     * Beide Hälften des Wegs müssen dieselbe Frist kennen: Prüfte sie nur
     * `deleteUser()`, bliebe der Dialog aus und der Nutzer liefe in ein 403,
     * ohne dass ihm jemand die Bestätigung angeboten hätte.
     */
    public function testAnAgedConfirmationReopensTheDialog(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->confirmPassword(600);
        $this->actingAs(User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->call('startConfirmingPassword', 'delete-user')
            ->assertSet('confirmingPassword', true)
            ->assertDispatched('confirming-password');
    }

    /**
     * Innerhalb der Frist bleibt der Dialog aus — sonst verlangte jeder Klick
     * in diesem Bereich eine erneute Bestätigung.
     */
    public function testAFreshConfirmationPassesTheDialog(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->confirmPassword();
        $this->actingAs(User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->call('startConfirmingPassword', 'delete-user')
            ->assertSet('confirmingPassword', false)
            ->assertDispatched('password-confirmed');
    }

    /**
     * Der Warndialog geht erst nach dem Nachweis auf — sonst stünde die letzte
     * Rückfrage vor der Hürde statt hinter ihr.
     */
    public function testTheWarningDialogOpensOnlyAfterTheConfirmation(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->actingAs(User::factory()->create());

        Livewire::test(DeleteUserForm::class)
            ->assertSet('confirmingUserDeletion', false)
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->call('confirmUserDeletion')
            ->assertSet('confirmingUserDeletion', true);
    }

    /**
     * Der Durchstich: Ein Konto ohne nutzbares Passwort weist seine Sitzung per
     * Assertion nach und kommt damit an die Löschung — sonst bliebe ihm der
     * Weg zum eigenen „Recht auf Vergessen" über die Oberfläche versperrt.
     */
    public function testAPasskeyConfirmationOpensTheAccountDeletion(): void
    {
        $this->skipWithoutAccountDeletion();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $authenticator = VirtualAuthenticator::create();
        $credential = $authenticator->registerFor($user);

        $this->actingAs($user);

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

        Livewire::test(DeleteUserForm::class)->call('deleteUser');

        $this->assertNull($user->fresh());
    }

    /**
     * Livewire ruft die Komponente ohne die `auth`-Middleware der Seite auf; sie
     * muss deshalb selbst abweisen, wenn niemand angemeldet ist — sonst liefe die
     * Löschung gegen ein Konto, das es nicht gibt.
     */
    public function testDeletingAbortsWhenNobodyIsAuthenticated(): void
    {
        $this->skipWithoutAccountDeletion();

        $this->confirmPassword();

        Livewire::test(DeleteUserForm::class)
            ->call('deleteUser')
            ->assertStatus(401);
    }

    /**
     * Jetstream registriert denselben Alias; die App gewinnt nur, weil ihr
     * Provider später bootet. Kippt die Reihenfolge, rendert die Profilseite
     * still Jetstreams Formular — und das prüft das Passwort selbst am
     * Bestätigungspfad vorbei.
     */
    public function testTheProfilePageAliasResolvesToThisForm(): void
    {
        $this->assertInstanceOf(DeleteUserForm::class, Livewire::new('profile.delete-user-form'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('passkey-confirm');
    }

    private function skipWithoutAccountDeletion(): void
    {
        if (! Features::hasAccountDeletionFeatures()) {
            $this->markTestSkipped('Account deletion is not enabled.');
        }
    }
}
