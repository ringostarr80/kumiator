<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\UpdatePasswordForm;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Livewire\Livewire;
use Tests\Support\InsertsSessions;
use Tests\TestCase;

/**
 * Wer eine fremde Sitzung hält, darf aus ihr nicht das Passwort erraten: Ein
 * Treffer überdauert die Sitzung und öffnet die Passkey- und 2FA-Verwaltung.
 * Nach dem Limit lehnt die Sperre deshalb auch das richtige Passwort ab —
 * bestätigte sie es, verriete der Treffer sich, und das Raten liefe weiter.
 */
final class PasswordAttemptThrottleTest extends TestCase
{
    use InsertsSessions;
    use RefreshDatabase;

    private const string CONFIRM_PASSWORD_URL_PATH = '/user/confirm-password';
    private const string PASSWORD = 'password';
    private const string WRONG_PASSWORD = 'wrong-password';
    private const int ALLOWED_ATTEMPTS = 5;

    /** Am Dialog einer Komponente, die das Projekt nicht überschreibt. */
    public function testTheConfirmationDialogRejectsEvenTheCorrectPasswordAfterTheLimit(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(TwoFactorAuthenticationForm::class);

        for ($attempt = 0; $attempt < self::ALLOWED_ATTEMPTS; $attempt++) {
            $component->set('confirmablePassword', self::WRONG_PASSWORD)
                ->call('confirmPassword')
                ->assertHasErrors(['confirmable_password' => __('This password does not match our records.')]);
        }

        $component->set('confirmablePassword', self::PASSWORD)
            ->call('confirmPassword')
            ->assertHasErrors(['confirmable_password' => __('validation.password_attempts_left')]);

        $this->assertNull(session('auth.password_confirmed_at'));
    }

    /** Sonst meldete fünfmal Enter auf das leere Feld den Eigentümer überall ab. */
    public function testEmptyConfirmationsInTheDialogDoNotCount(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(TwoFactorAuthenticationForm::class)
            ->call('startConfirmingPassword', 'enable');

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $component->call('confirmPassword')
                ->assertHasErrors('confirmable_password');
        }

        $component->set('confirmablePassword', self::PASSWORD)
            ->call('confirmPassword')
            ->assertHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    /** Kein Passwort kann nur aus Leerzeichen bestehen, ein Vergleich damit rät nichts. */
    public function testWhitespaceConfirmationsInTheDialogDoNotCount(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(TwoFactorAuthenticationForm::class)
            ->call('startConfirmingPassword', 'enable');

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $component->set('confirmablePassword', ' ')
                ->call('confirmPassword')
                ->assertHasErrors('confirmable_password');
        }

        $component->set('confirmablePassword', self::PASSWORD)
            ->call('confirmPassword')
            ->assertHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function testTheConfirmationPageNamesTheLockInsteadOfAWrongPassword(): void
    {
        $this->actingAs(User::factory()->create());
        $this->exhaustAttemptsOnTheConfirmationPage();

        $this->from(self::CONFIRM_PASSWORD_URL_PATH)
            ->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertRedirect(route('login'));

        $this->assertNull(session('auth.password_confirmed_at'));

        $this->startTheNextRequest();
        $this->get(route('login'))->assertSee(__('validation.password_attempts_left'));
    }

    /**
     * Bliebe die ratende Sitzung angemeldet, wartete sie das Fenster ab und riete
     * weiter.
     */
    public function testTheLockLogsTheGuessingSessionOut(): void
    {
        $this->logIn(User::factory()->create());

        $this->lockTheAccountOnTheConfirmationPage();

        $this->startTheNextRequest();
        $this->assertGuest('web');
    }

    /** Mit dem Cookie holte sich der Angreifer sonst gleich die nächste Sitzung. */
    public function testTheLockVoidsEveryRecallerCookie(): void
    {
        $this->actingAs($user = User::factory()->create(['remember_token' => $stolen = Str::random(60)]));

        $this->lockTheAccountOnTheConfirmationPage();

        $token = $user->fresh()?->getRememberToken();
        $this->assertNotSame($stolen, $token);
        $this->assertSame(60, strlen((string)$token));
    }

    /**
     * Der neue Token ist keine Bearbeitung des Kontos, Laravels eigene Rotation
     * beim Logout lässt den Zeitstempel ebenso stehen.
     */
    public function testTheLockLeavesTheLastChangeOfTheAccountAlone(): void
    {
        $lastChange = '2026-01-01 12:00:00';
        $this->actingAs($user = User::factory()->create(['updated_at' => $lastChange]));

        $this->lockTheAccountOnTheConfirmationPage();

        $this->assertSame($lastChange, $user->fresh()?->updated_at?->toDateTimeString());
    }

    /** Entwertet ist er schon, der Browser schickte ihn sonst aber weiter mit. */
    public function testTheLockClearsTheRecallerCookieOfTheGuessingBrowser(): void
    {
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->actingAs(User::factory()->create())
            ->withCookie($guard->getRecallerName(), 'issued-with-the-old-token');

        $this->exhaustAttemptsOnTheConfirmationPage();

        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertRedirect(route('login'))
            ->assertCookieExpired($guard->getRecallerName());
    }

    /**
     * Im Dialog ist der Default-Guard Sanctums `RequestGuard`: Livewire zieht
     * `auth:sanctum` der Profilseite auf seinen Update-Request nach.
     */
    public function testTheLockInTheDialogClearsTheRecallerCookieAsWell(): void
    {
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->actingAs(User::factory()->create())->get('/user/profile')->assertOk();
        $component = Livewire::test(TwoFactorAuthenticationForm::class);

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $component->set('confirmablePassword', self::WRONG_PASSWORD)
                ->call('confirmPassword');
        }

        $this->assertTrue(Cookie::hasQueued($guard->getRecallerName()));
    }

    /**
     * Ein Angreifer kann mehrere Sitzungen halten, und nichts unterscheidet die
     * ratende von der des Eigentümers.
     */
    public function testTheLockEndsEveryOtherSessionOfTheAccount(): void
    {
        Config::set('session.driver', 'database');
        $this->actingAs($user = User::factory()->create());
        $this->insertSession('owner-device', $user->id);
        $this->insertSession('other-account', User::factory()->create()->id);

        $this->lockTheAccountOnTheConfirmationPage();

        $this->assertSame(0, DB::table('sessions')->where('id', 'owner-device')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'other-account')->count());
    }

    public function testTheLockIsRecordedInTheAuditLog(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->lockTheAccountOnTheConfirmationPage();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_attempts_locked_out')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Fremde Sitzungen erreicht das Löschen nur unter dem Treiber `database`; unter
     * jedem anderen endet eine Sitzung erst mit ihrem eigenen Versuch über der
     * Grenze.
     */
    public function testAnAttemptAboveTheLimitFromANewSessionLogsItOutAsWell(): void
    {
        $user = User::factory()->create();
        $this->logIn($user);
        $this->lockTheAccountOnTheConfirmationPage();
        $this->startTheNextRequest();

        $this->logIn($user);
        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertRedirect(route('login'));

        $this->startTheNextRequest();
        $this->assertGuest('web');
    }

    public function testThePasswordChangeRejectsEvenTheCorrectPasswordAfterTheLimit(): void
    {
        $this->actingAs($user = User::factory()->create());

        for ($attempt = 0; $attempt < self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->put('/user/password', $this->passwordChange(self::WRONG_PASSWORD))
                ->assertSessionHasErrorsIn('updatePassword', ['current_password' => __('validation.current_password')]);
        }

        $this->put('/user/password', $this->passwordChange(self::PASSWORD))
            ->assertSessionHasErrorsIn(
                'updatePassword',
                ['current_password' => __('validation.password_attempts_left')],
            );

        $this->assertTrue(Hash::check(self::PASSWORD, (string) $user->fresh()?->password));
    }

    /**
     * Getrennte Zähler vervielfachten die Versuche um die Zahl der Wege; der
     * Dialog und das Formular zählen deshalb gemeinsam.
     */
    public function testFailedConfirmationsAlsoLockTheEmailChange(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => 'original@example.com']));
        $this->exhaustAttemptsOnTheConfirmationPage();

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasErrorsIn(
            'updateProfileInformation',
            ['current_password' => __('validation.password_attempts_left')],
        );

        $this->assertNull($user->fresh()?->pending_email);
    }

    public function testTheLivewirePasswordFormSharesTheLock(): void
    {
        $this->actingAs(User::factory()->create());
        $this->exhaustAttemptsOnTheConfirmationPage();

        Livewire::test(UpdatePasswordForm::class)
            ->set('state', $this->passwordChange(self::PASSWORD))
            ->call('updatePassword')
            ->assertHasErrors(['current_password' => 'password_attempts_left']);
    }

    /**
     * Auch ohne Passwort-Login wird das genannte Passwort verglichen, solange die
     * Sitzung nicht frisch bestätigt ist — ein Treffer belegt dort ein
     * abgephishtes Passwort und verdient dieselbe Grenze.
     */
    public function testGuessesAtAnAccountWithoutPasswordLoginCountAsWell(): void
    {
        $this->actingAs(User::factory()->create(['password_login_disabled_at' => now()]));

        for ($attempt = 0; $attempt < self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->put('/user/password', $this->passwordChange(self::WRONG_PASSWORD))
                ->assertSessionHasErrorsIn('updatePassword', ['current_password' => __('validation.current_password')]);
        }

        $this->put('/user/password', $this->passwordChange(self::PASSWORD))
            ->assertSessionHasErrorsIn(
                'updatePassword',
                ['current_password' => __('validation.password_attempts_left')],
            );
    }

    /**
     * Wer das Passwort nennt, rät nicht. Sonst sperrte sich der Eigentümer aus,
     * während er an den Regeln für das neue Passwort scheitert.
     */
    public function testCorrectPasswordsDoNotCountWhenTheNewOneIsRejected(): void
    {
        $this->actingAs($user = User::factory()->create());
        $mistypedConfirmation = ['password_confirmation' => 'mistyped'] + $this->passwordChange(self::PASSWORD);

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->put('/user/password', $mistypedConfirmation)
                ->assertSessionHasErrorsIn('updatePassword', 'password')
                ->assertSessionDoesntHaveErrors('current_password', null, 'updatePassword');
        }

        $this->put('/user/password', $this->passwordChange(self::PASSWORD))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', (string) $user->fresh()?->password));
    }

    public function testCorrectConfirmationsDoNotCount(): void
    {
        $this->actingAs(User::factory()->create());

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
                ->assertSessionHasNoErrors();
        }
    }

    /**
     * Zurückgebucht, nicht zurückgesetzt: Ein Treffer des Eigentümers löschte
     * sonst die Fehlversuche einer ratenden Sitzung daneben.
     */
    public function testAHitDoesNotForgiveEarlierFailures(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::WRONG_PASSWORD])
            ->assertSessionHasErrors('password');
        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        for ($attempt = 1; $attempt < self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::WRONG_PASSWORD])
                ->assertSessionHasErrors('password');
        }

        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertRedirect(route('login'));
    }

    /** Ein Treffer bleibt ein Treffer, auch wo das Passwort nicht mehr anmeldet. */
    public function testCorrectPasswordsAtAnAccountWithoutPasswordLoginDoNotCountEither(): void
    {
        $this->actingAs(User::factory()->create(['password_login_disabled_at' => now()]));

        for ($attempt = 0; $attempt <= self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->put('/user/password', $this->passwordChange(self::PASSWORD))
                ->assertSessionHasErrorsIn(
                    'updatePassword',
                    ['current_password' => __('validation.password_login_enabled')],
                );
        }
    }

    public function testTheLockOnlyAffectsTheGuessedAccount(): void
    {
        $this->actingAs(User::factory()->create());
        $this->exhaustAttemptsOnTheConfirmationPage();

        $this->actingAs(User::factory()->create())
            ->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    /** Sonst bliebe das Konto nach fünf Fehlern auch für den Eigentümer ohne Passwortprüfung. */
    public function testTheLockEndsWithTheWindow(): void
    {
        $this->actingAs($user = User::factory()->create());
        $this->lockTheAccountOnTheConfirmationPage();

        $this->travel(1)->minutes();

        $this->actingAs($user)
            ->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    private function exhaustAttemptsOnTheConfirmationPage(): void
    {
        for ($attempt = 0; $attempt < self::ALLOWED_ATTEMPTS; $attempt++) {
            $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::WRONG_PASSWORD])
                ->assertSessionHasErrors('password');
        }
    }

    private function lockTheAccountOnTheConfirmationPage(): void
    {
        $this->exhaustAttemptsOnTheConfirmationPage();

        $this->post(self::CONFIRM_PASSWORD_URL_PATH, ['password' => self::PASSWORD])
            ->assertRedirect(route('login'));
    }

    /**
     * Über `/login`, nicht `actingAs()`: Nur so steht die Anmeldung in der
     * Sitzung, die die Sperre beenden soll.
     */
    private function logIn(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->startTheNextRequest();
        $this->assertAuthenticatedAs($user, 'web');
    }

    /**
     * Im Test überlebt der Guard samt Nutzer die Anfrage; eine echte nächste
     * Anfrage liest die Anmeldung neu aus der Sitzung.
     */
    private function startTheNextRequest(): void
    {
        Auth::forgetGuards();
    }

    /** @return array{current_password: string, password: string, password_confirmation: string} */
    private function passwordChange(string $currentPassword): array
    {
        return [
            'current_password' => $currentPassword,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];
    }
}
