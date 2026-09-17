<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ConfirmsPassword;
use Tests\Support\InsertsSessions;
use Tests\TestCase;

/**
 * Deckt das Ab- und Wiedereinschalten der Passwort-Anmeldung samt der Sperre ab,
 * die verhindert, dass ein Konto sich selbst aussperrt.
 */
final class PasswordLoginSwitchTest extends TestCase
{
    use ConfirmsPassword;
    use InsertsSessions;
    use RefreshDatabase;

    public function testDisablingRequiresAtLeastOnePasskey(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasErrors('passkeys');

        $this->assertNull($user->fresh()?->password_login_disabled_at);
    }

    public function testDisablingWorksOnceAPasskeyExists(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertSee(__('app.password_login_state_off'))
            ->assertHasNoErrors();

        $this->assertNotNull($user->fresh()?->password_login_disabled_at);
    }

    public function testEnablingClearsTheSwitch(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertSee(__('app.password_login_state_on'));

        $this->assertNull($user->fresh()?->password_login_disabled_at);
    }

    /**
     * Der Knopf verwirft das Passwort, also muss das dranstehen, bevor er gedrückt
     * wird — hinterher ist die Entscheidung gefallen.
     */
    public function testTheEnableButtonWarnsThatThePasswordIsDiscarded(): void
    {
        $this->confirmPassword();

        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->assertSee(__('app.password_login_enable_warning'))
            ->call('enablePasswordLogin')
            ->assertDontSee(__('app.password_login_enable_warning'));
    }

    /**
     * Wer seit dem Abschalten ein neues Passwort gesetzt hat, verliert beim
     * Wiedereinschalten nichts — eine Warnung davor wäre eine Falschaussage.
     */
    public function testTheEnableButtonStaysQuietWhenThePasswordIsNewerThanTheDisabling(): void
    {
        $this->confirmPassword();

        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password = 'seither-gesetzt';
        $user->saveOrFail();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->assertDontSee(__('app.password_login_enable_warning'))
            ->call('enablePasswordLogin')
            ->assertSee(__('app.password_login_state_on'));

        $this->assertTrue(Hash::check('seither-gesetzt', $user->refresh()->password));
    }

    /**
     * Der neue Hash beendet jede Sitzung, die noch den alten trägt — die eigene
     * eingeschlossen, wenn sie ihn nicht erfährt: Livewire lässt
     * `AuthenticateSession` vor der Aktion laufen, nicht danach.
     */
    public function testTheOwnSessionSurvivesTheDiscardedPassword(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();

        $this->confirmPassword();
        $this->actingAs($user);

        // Der erste Seitenaufruf legt den Hash in die Sitzung, wie im Browser.
        $this->get('/user/profile')->assertOk();

        Livewire::test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertHasNoErrors();

        $this->get('/user/profile')->assertOk();
    }

    /**
     * Das andere Gerät trägt in seiner Sitzung noch den alten Hash und erfährt
     * vom Wechsel nichts — sein nächster Aufruf endet auf der Anmeldeseite, wie
     * es der Warntext ankündigt.
     */
    public function testTheOtherDevicesAreSignedOutByTheDiscardedPassword(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();

        $this->confirmPassword();
        $this->actingAs($user);

        $this->get('/user/profile')->assertOk();
        $hashKey = 'password_hash_' . Auth::getDefaultDriver();
        $hashOnTheOtherDevice = Session::get($hashKey);

        Livewire::test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertHasNoErrors();

        // Dieselbe Sitzung spielt das andere Gerät: Was dort liegt, ist der Stand
        // von vor dem Wechsel.
        Session::put($hashKey, $hashOnTheOtherDevice);

        $this->get('/user/profile')->assertRedirect(route('login', absolute: false));
    }

    /**
     * Schaltet ein zweiter Tab den Passwort-Login um, muss die Seite beim nächsten
     * Rendern den neuen Stand zeigen — sonst bietet sie einen Knopf an, der nichts
     * mehr bewirkt.
     */
    public function testTheStateFollowsAChangeMadeElsewhere(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $component = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->assertSee(__('app.password_login_state_on'));

        $elsewhere = User::query()->whereKey($user->getKey())->firstOrFail();
        $elsewhere->password_login_disabled_at = now();
        $elsewhere->saveOrFail();

        // Ein Folge-Request holt den Nutzer über die Sitzung neu aus der Datenbank.
        // Im Test hält der Guard die Instanz fest, die `actingAs()` ihm gegeben hat.
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $guard->setUser(User::query()->whereKey($user->getKey())->firstOrFail());

        $component->call('$refresh')
            ->assertSee(__('app.password_login_state_off'))
            ->assertDontSee(__('app.password_login_state_on'));
    }

    public function testDisablingIsAudited(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_login_disabled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    public function testEnablingIsAudited(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin');

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'auth')->where('event', 'password_login_enabled')->count(),
        );
    }

    /**
     * Der Zeitstempel hält fest, seit wann das Konto ohne Passwort auskommt. Ein
     * zweiter Tab, der den Passwort-Login noch als offen zeigt, darf diese Aussage
     * nicht auf den heutigen Klick verschieben.
     */
    public function testDisablingAgainKeepsTheOriginalTimestamp(): void
    {
        $this->confirmPassword();

        $disabledAt = now()->subDays(3);
        $user = User::factory()->create(['password_login_disabled_at' => $disabledAt]);
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertSee(__('app.password_login_state_off'))
            ->assertHasNoErrors();

        $this->assertSame(
            $disabledAt->toDateTimeString(),
            $user->fresh()?->password_login_disabled_at?->toDateTimeString(),
        );
        $this->assertSame(0, Activity::query()->where('event', 'password_login_disabled')->count());
    }

    /**
     * Zwei Requests, die beide den Schalter noch offen sahen: Der zweite trifft
     * auf die Zeile, die der erste inzwischen abgeschaltet hat. Er darf weder
     * den Stempel verschieben noch am fehlenden Passkey scheitern — der Login
     * ist zu, und genau das soll er zeigen.
     */
    public function testARacingDisableFindsTheSwitchAlreadyOffOnTheLockedRow(): void
    {
        $this->confirmPassword();

        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        $form = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class);

        app(LoginMethodChangerContract::class)->disablePasswordLogin($user);
        $this->travelTo(Carbon::parse('2026-01-01 10:00:05'));

        $form->call('disablePasswordLogin')
            ->assertSee(__('app.password_login_state_off'))
            ->assertHasNoErrors();

        $this->assertSame('2026-01-01 10:00:00', $user->fresh()?->password_login_disabled_at?->toDateTimeString());
        $this->assertSame(1, Activity::query()->where('event', 'password_login_disabled')->count());
    }

    /** Ohne vorheriges Abschalten protokollierte der Eintrag ein Ereignis, das nie stattfand. */
    public function testEnablingWithoutAPriorDisableWritesNothing(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertSee(__('app.password_login_state_on'))
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()?->password_login_disabled_at);
        $this->assertSame(0, Activity::query()->where('event', 'password_login_enabled')->count());
    }

    public function testDisablingRequiresConfirmedPassword(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertForbidden();

        $this->assertNull($user->fresh()?->password_login_disabled_at);
    }

    public function testEnablingRequiresConfirmedPassword(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertForbidden();

        $this->assertNotNull($user->fresh()?->password_login_disabled_at);
    }

    /**
     * Das Abschalten beendet jede andere Sitzung des Kontos. Wie der
     * Zwangs-Logout verlangt es dafür einen Nachweis aus diesem Vorgang — nicht
     * die Bestätigung von vor Stunden, mit der die übrigen Aktionen des
     * Formulars auskommen.
     */
    public function testAnAgedConfirmationNoLongerReachesTheDisabling(): void
    {
        $this->confirmPassword(600);

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertForbidden();

        $this->assertNull($user->fresh()?->password_login_disabled_at);
    }

    /**
     * Beide Hälften des Wegs müssen dieselbe Frist kennen: Prüfte sie nur
     * `disablePasswordLogin()`, bliebe der Dialog aus und der Nutzer liefe in
     * ein 403, ohne dass ihm jemand die Bestätigung angeboten hätte. Die
     * Blade-Komponente reicht die Aktion als Hash ihres `wire:then`-Werts
     * durch; der Test hält beide Seiten an derselben Bildung fest.
     */
    public function testAnAgedConfirmationReopensTheDialogBeforeDisabling(): void
    {
        $this->confirmPassword(600);

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $confirmableId = md5('disablePasswordLogin');

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->assertSeeHtml("startConfirmingPassword('{$confirmableId}')")
            ->call('startConfirmingPassword', $confirmableId)
            ->assertSet('confirmingPassword', true)
            ->assertDispatched('confirming-password');
    }

    /**
     * Die kurze Frist gilt nur dem Abschalten. Die Registrierung hängt bewusst
     * an derselben Frist wie die JSON-Endpunkte dahinter — ein strengerer
     * Dialog davor schützte sie nicht, er kostete nur Klicks.
     */
    public function testTheOtherActionsOfTheFormKeepTheLongerConfirmation(): void
    {
        $this->confirmPassword(600);

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $form = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('startConfirmingPassword', md5('startPasskeyRegistration'))
            ->assertSet('confirmingPassword', false);

        $form->assertDispatched('password-confirmed');

        $form->call('startPasskeyRegistration')
            ->assertDispatched('passkey-registration-confirmed');
    }

    /**
     * Das Einschalten meldet andere Geräte höchstens ab, sperrt aber niemanden
     * aus; es bleibt bei der Frist der übrigen Profilbereiche.
     */
    public function testEnablingStillAcceptsAnAgedConfirmation(): void
    {
        $this->confirmPassword(600);

        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()?->password_login_disabled_at);
    }

    public function testTheLastPasskeyCannotBeDeletedWhilePasswordLoginIsOff(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('deletePasskey', $passkey->id)
            ->assertSee(__('app.passkey_delete_last_blocked'))
            ->assertHasErrors('passkey_delete');

        $this->assertModelExists($passkey);
    }

    public function testASecondPasskeyCanStillBeDeletedWhilePasswordLoginIsOff(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();
        $second = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('deletePasskey', $second->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($second);
    }

    public function testTheLastPasskeyRemainsDeletableWhilePasswordLoginIsOn(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('deletePasskey', $passkey->id)
            ->assertHasNoErrors();

        $this->assertModelMissing($passkey);
    }

    /**
     * Über den letzten Anmeldeweg entscheidet die Weiche zwischen geschütztem und
     * ungeschütztem Löschen. Fällt sie auf dem beim Request geladenen Auth-Model,
     * gilt der Stand von vor dem Klick — ein zweiter Tab, der den Passwort-Login
     * inzwischen abgeschaltet hat, bleibt unsichtbar, und das Konto verliert Passkey
     * und Passwort-Login zugleich.
     */
    public function testDeletingTheLastPasskeyRespectsASwitchFlippedElsewhere(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $this->actingAs($user);

        $otherTab = User::whereKey($user->getKey())->firstOrFail();
        $otherTab->password_login_disabled_at = now();
        $otherTab->saveOrFail();

        Livewire::test(PasskeyManagerForm::class)
            ->call('deletePasskey', $passkey->id)
            ->assertHasErrors('passkey_delete');

        $this->assertModelExists($passkey);
    }

    /**
     * Die Abschaltung nimmt dem Passwort den Login — ein Recaller-Cookie, das mit
     * ebendiesem Passwort entstanden ist, meldet danach trotzdem weiter an:
     * `SessionGuard::userFromRecaller()` vergleicht nur den Token und läuft an
     * `Fortify::authenticateUsing` vorbei. Auch der Hash-Vergleich in
     * `AuthenticateSession` rettet nichts, weil das Abschalten den Passwort-Hash
     * unangetastet lässt.
     */
    public function testDisablingInvalidatesAnExistingRecallerCookie(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        PasskeyCredential::factory()->for($user)->create();

        // Der Cookie, den die Anmeldung mit dem abgephishten Passwort hinterlassen hat.
        $stolenRecaller = $user->id . '|' . $user->getRememberToken() . '|' . $user->getAuthPassword();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        // Frischer Guard mit dem alten Cookie und ohne Sitzung: genau die Lage des
        // Angreifers, dessen Session inzwischen abgelaufen ist.
        Auth::forgetGuards();

        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $request = Request::create('/');
        $request->cookies->set($guard->getRecallerName(), $stolenRecaller);
        $guard->setRequest($request);

        $this->assertFalse(
            $guard->check(),
            'Der vor dem Abschalten erlangte Recaller-Cookie darf danach nicht mehr anmelden.',
        );
    }

    /**
     * Abgeschaltet wird der Passwort-Login dann, wenn der Verdacht besteht, dass
     * jemand anders im Konto sitzt. Bliebe dessen Sitzung bestehen, käme die Maßnahme
     * genau für den Fall zu spät, für den sie gedacht ist.
     */
    public function testDisablingTerminatesTheOtherSessionsOfTheAccount(): void
    {
        Config::set('session.driver', 'database');

        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $ownSessionId = Session::getId();
        $this->insertSession($ownSessionId, $user->id);
        $this->insertSession('other-device', $user->id);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        $this->assertSame(
            0,
            DB::table('sessions')->where('id', 'other-device')->count(),
            'Die Sitzung des anderen Geräts muss mit dem Abschalten enden.',
        );
        $this->assertSame(
            1,
            DB::table('sessions')->where('id', $ownSessionId)->count(),
            'Die eigene Sitzung muss bleiben, sonst wirft das Abschalten den Nutzer selbst hinaus.',
        );
    }

    /**
     * Derselbe Widerruf über das Sitzungs-Formular hinterlässt einen Eintrag. Ohne
     * ihn bliebe die eingreifendste Wirkung des Abschaltens — fremde Geräte fliegen
     * hinaus, ausgestellte Recaller-Cookies verfallen — im Protokoll unsichtbar.
     */
    public function testTheTerminatedSessionsAreAudited(): void
    {
        Config::set('session.driver', 'database');

        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->insertSession(Session::getId(), $user->id);
        $this->insertSession('other-device', $user->id);
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'other_sessions_logged_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame(1, $activity->properties?->get('terminated_session_count'));
    }

    /**
     * Liegen die Sitzungen nicht in der Datenbank, kommt der Widerruf nicht an
     * sie heran — der Eintrag behauptete dann Sitzungsenden, die es nicht gab.
     * Das Sitzungs-Formular schweigt in derselben Lage; die Abschaltung selbst
     * bleibt protokolliert.
     */
    public function testTheSessionAuditIsSkippedWithoutDatabaseSessions(): void
    {
        Config::set('session.driver', 'array');

        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        $this->assertSame(0, Activity::query()->where('event', 'other_sessions_logged_out')->count());
        $this->assertSame(1, Activity::query()->where('event', 'password_login_disabled')->count());
    }

    /**
     * Ein vor dem Abschalten verschickter Link lebt bis zu einer Stunde weiter.
     * Der Guard im Reset hält ihn nur auf, solange der Passwort-Login abgeschaltet
     * ist — ist er wieder offen, setzt der Link ein Passwort seiner Wahl. Wer
     * abschaltet, entwertet ihn deshalb mit, wie Sitzungen und Recaller-Cookie.
     */
    public function testDisablingInvalidatesAnOpenPasswordResetToken(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $token = Password::broker()->createToken($user);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        // Der Passwort-Login steht wieder offen: Ab hier hält den alten Link nichts
        // mehr auf ausser seiner Entwertung. Der Reset-Endpunkt steht nur Gästen offen.
        $user->refresh();
        $user->password_login_disabled_at = null;
        $user->saveOrFail();
        Auth::logout();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $user->refresh();

        $this->assertFalse(Hash::check('brand-new-password', $user->password));
    }

    /**
     * Abgeschaltet ist der Passwort-Login, sobald der Dienst committet hat; was
     * danach folgt, räumt nur noch hinterher auf. Scheitert dort etwas, muss der
     * Nachweis, wer wann den Anmeldeweg verändert hat, trotzdem stehen — sonst
     * fehlte er ausgerechnet dann, wenn etwas Ungewöhnliches passiert ist.
     */
    public function testTheDisableIsAuditedEvenWhenTheFollowUpWritesFail(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        // Echter Save-Hook statt Mock: Der Remember-Token-Write des Sitzungswiderrufs wirft.
        User::saving(function (User $model): void {
            if ($model->isDirty('remember_token')) {
                throw new \RuntimeException('boom beim Remember-Token-Save');
            }
        });

        try {
            Livewire::actingAs($user)
                ->test(PasskeyManagerForm::class)
                ->call('disablePasswordLogin');
            $this->fail('Erwartete RuntimeException aus dem Remember-Token-Save.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $this->assertNotNull($user->fresh()?->password_login_disabled_at);
        $this->assertSame(1, Activity::query()->where('event', 'password_login_disabled')->count());
    }

    /**
     * Das Ende der fremden Sitzungen ist die Schutzwirkung des Abschaltens. Ein
     * Audit-Sink, der gerade nicht schreibt, darf sie nicht aufhalten.
     */
    public function testTheOtherSessionsEndEvenWhenTheAuditWriteFails(): void
    {
        Config::set('session.driver', 'database');

        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->insertSession(Session::getId(), $user->id);
        $this->insertSession('other-device', $user->id);

        Activity::saving(function (): void {
            throw new \RuntimeException('boom beim Audit-Insert');
        });

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('disablePasswordLogin')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertNotNull($user->fresh()?->password_login_disabled_at);
    }

    /**
     * Ob überhaupt etwas einzuschalten ist, entscheidet der gespeicherte Stand und
     * nicht die Instanz, die der Aufruf mitbringt. Wer daneben liest, kehrt um,
     * ohne zu schreiben, und meldet dem Nutzer einen offenen Passwort-Login, den
     * es nicht gibt.
     */
    public function testEnablingDecidesOnTheStoredStateRatherThanTheLoadedInstance(): void
    {
        $this->confirmPassword();

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        // Echte Nebenläufigkeit ist in der Suite nicht darstellbar — eine Verbindung,
        // eine Transaktion. Der veraltete Stand auf der Instanz ist derselbe Zustand,
        // den ein gleichzeitiges Abschalten hinterlässt.
        $stored = User::query()->findOrFail($user->id);
        $stored->password_login_disabled_at = now();
        $stored->saveOrFail();

        Activity::query()->delete();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class)
            ->call('enablePasswordLogin')
            ->assertSee(__('app.password_login_state_on'))
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()?->password_login_disabled_at);
        $this->assertSame(1, Activity::query()->where('event', 'password_login_enabled')->count());
    }
}
