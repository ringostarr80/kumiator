<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

/**
 * Hält die Wege zu, die ein Passwort einem Konto sonst offen lässt, sobald es
 * den Passwort-Login abgeschaltet hat: die Anmeldung selbst, den E-Mail-Reset,
 * über den sich ein neues Passwort beschaffen ließe, und die
 * `current_password`-Abfragen der beiden Profil-Formulare.
 */
final class PasswordLoginDisabledTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    private const string LOGIN_URL_PATH = '/login';
    private const string PASSWORD = 'password';

    public function testCorrectCredentialsNoLongerAuthenticate(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertGuest();
    }

    public function testCorrectCredentialsStillAuthenticateWithoutTheSwitch(): void
    {
        $user = User::factory()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticated();
    }

    /**
     * Der Eintrag ist ein Warnsignal, kein Rauschen: Wer hier auftaucht, kennt
     * ein gültiges Passwort zu einem Konto, das sich damit nicht anmelden kann.
     */
    public function testRejectedLoginIsAuditedWithItsOwnEvent(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_password_disabled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertArrayHasKey('email_hash', $properties);
    }

    public function testRejectedLoginDoesNotAlsoCountAsAGenericFailure(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(0, Activity::query()->where('event', 'login_failed')->count());
    }

    /**
     * Ein falsches Passwort darf weiterhin als generischer Fehlversuch zählen —
     * sonst verschluckte der Marker den Brute-Force-Trail solcher Konten.
     */
    public function testWrongCredentialsStillCountAsAGenericFailure(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(1, Activity::query()->where('event', 'login_failed')->count());
        $this->assertSame(0, Activity::query()->where('event', 'login_password_disabled')->count());
    }

    /**
     * Fällt das Log aus, muss der Nutzer dieselbe reguläre Fehlermeldung sehen —
     * ein 500er machte die Verfügbarkeit des Audit-Logs nach außen sichtbar und
     * verriete nebenbei, dass es dieses Konto gibt.
     */
    public function testTheRejectionSurvivesAFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Schema::drop('activity_log');

        $response = $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertGuest();
        $response->assertRedirect();
        $response->assertSessionHasErrors();
        Exceptions::assertReported(QueryException::class);
    }

    public function testResetLinkIsNotSent(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertNothingSent();
    }

    public function testResetLinkIsStillSentWithoutTheSwitch(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * Die Antwort muss dieselbe bleiben: Wiche sie ab, verriete sie einem
     * Unbeteiligten, dass zu dieser Adresse ein Konto mit Passkey existiert.
     */
    public function testTheRequestAnswersTheSameAsForAnyOtherAccount(): void
    {
        Notification::fake();

        $disabled = User::factory()->create(['password_login_disabled_at' => now()]);
        $regular = User::factory()->create();

        $disabledResponse = $this->post('/forgot-password', ['email' => $disabled->email]);
        $regularResponse = $this->post('/forgot-password', ['email' => $regular->email]);

        $this->assertSame($regularResponse->getStatusCode(), $disabledResponse->getStatusCode());
        $disabledResponse->assertSessionHas('status', __('passwords.sent'));
        $regularResponse->assertSessionHas('status', __('passwords.sent'));
    }

    public function testTheSuppressedSendIsVisibleInTheAuditTrail(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post('/forgot-password', ['email' => $user->email]);

        $activity = Activity::query()
            ->where('event', 'password_reset_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertTrue($activity->properties?->get('notification_suppressed'));
    }

    /**
     * Ein vor dem Abschalten verschickter Link lebt bis zu einer Stunde weiter.
     * Löste er ein, taugte das gesetzte Passwort zwar nicht zur Anmeldung, wohl
     * aber zur Passwortbestätigung — und damit zur Passkey-Verwaltung.
     */
    public function testAStaleResetTokenCannotSetANewPassword(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);

        $user->password_login_disabled_at = now();
        $user->saveOrFail();

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
     * Der Broker löscht den Token erst hinter dem Callback, und die Abwehr des
     * Guards verlässt den Callback vorher. Der abgewehrte Link bliebe damit
     * scharf und griffe, sobald der Passwort-Login wieder offen steht.
     */
    public function testARefusedResetTokenIsSpentAnyway(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $payload = [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->post('/reset-password', $payload);

        $user->password_login_disabled_at = null;
        $user->saveOrFail();

        $this->post('/reset-password', $payload);

        $user->refresh();

        $this->assertFalse(Hash::check('brand-new-password', $user->password));
    }

    public function testTheResetActionRefusesDisabledAccounts(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->expectException(ValidationException::class);

        (new ResetUserPassword())->reset($user, ['password' => 'brand-new-password']);
    }

    /**
     * Wer den Link einlöst, hat Zugriff auf das Postfach des Kontos — das
     * stärkere Signal als ein bloß gekanntes Passwort, das der Login-Pfad
     * bereits festhält. Ohne den Eintrag endete der Trail bei der Anforderung.
     */
    public function testTheRefusedResetIsAudited(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_reset_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame('password_login_disabled', $activity->properties?->get('failure_reason'));
    }

    /**
     * Die `current_password`-Regel vergleicht den Hash direkt und käme ohne die
     * vorgeschaltete Regel an der Sperre vorbei — das abgeschaltete Passwort
     * setzte sich damit selbst neu.
     */
    public function testTheAccountPasswordCannotBeChangedWithIt(): void
    {
        $this->actingAs($user = User::factory()->create(['password_login_disabled_at' => now()]));

        $this->put('/user/password', [
            'current_password' => self::PASSWORD,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $refreshedUser = $user->fresh();

        $this->assertNotNull($refreshedUser);
        $this->assertFalse(Hash::check('brand-new-password', $refreshedUser->password));
    }

    /**
     * Derselbe Weg am zweiten Formular: Der E-Mail-Wechsel verlangt das aktuelle
     * Passwort, und ein bestätigter Wechsel legte die Kontoadresse — und damit
     * den Reset-Weg — in fremde Hand.
     */
    public function testTheEmailChangeCannotBeAuthorizedWithIt(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]));

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');

        $this->assertNull($user->fresh()?->pending_email);
        Notification::assertNothingSent();
    }

    /**
     * Derselbe Warnwert wie beim abgewiesenen Login: Die
     * `password_login_enabled`-Regel steht hinter `current_password`, der Eintrag
     * belegt also ein gültiges Passwort.
     */
    public function testTheBlockedPasswordChangeIsAuditedWithItsOwnReason(): void
    {
        $this->actingAs(User::factory()->create(['password_login_disabled_at' => now()]));
        Activity::query()->delete();

        $this->put('/user/password', [
            'current_password' => self::PASSWORD,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $activity = Activity::query()->where('event', 'password_update_failed')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('password_login_disabled', $activity->properties?->get('failure_reason'));
    }

    public function testTheBlockedEmailChangeIsAuditedWithItsOwnReason(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]));
        Activity::query()->delete();

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => self::PASSWORD,
        ]);

        $activity = Activity::query()->where('event', 'email_change_request_failed')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('password_login_disabled', $activity->properties?->get('failure_reason'));
    }

    /**
     * Der Rateversuch bleibt sichtbar: Stünde die `password_login_enabled`-Regel vor
     * `current_password`, verschluckte `bail` das Mismatch-Signal genau an den
     * Konten, an denen es am meisten zählt.
     */
    public function testAWrongPasswordIsStillAuditedAsAMismatch(): void
    {
        $this->actingAs(User::factory()->create(['password_login_disabled_at' => now()]));
        Activity::query()->delete();

        $this->put('/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $activity = Activity::query()->where('event', 'password_update_failed')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame('current_password_mismatch', $activity->properties?->get('failure_reason'));
    }

    /**
     * Nach dem Abschalten steht das Passwortfeld noch im alten Seitenaufbau —
     * der Abschnitt daneben ist eine eigene Komponente und rendert nicht neu.
     * Getragen wird der Wechsel von der bestätigten Sitzung; das danebenstehende
     * Feld darf ihn weder aufhalten noch seinen Inhaber als Angreifer ausweisen.
     */
    public function testTheEmailChangeAcceptsTheConfirmedSessionDespiteAStalePassword(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create([
            'email' => 'original@example.com',
            'password_login_disabled_at' => now(),
        ]));
        $this->confirmPassword();
        Activity::query()->delete();

        $this->put('/user/profile-information', [
            'name' => $user->name,
            'email' => 'neu@example.com',
            'current_password' => self::PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
        $this->assertNull(Activity::query()->where('event', 'email_change_request_failed')->first());
    }
}
