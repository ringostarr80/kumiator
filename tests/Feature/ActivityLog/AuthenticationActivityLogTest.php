<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Actions\Jetstream\DeleteUser;
use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\User;
use App\Services\WebAuthn\PasskeyLoginContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Validiert, dass der `LogAuthenticationActivityListener` Auth-Events korrekt
 * ins Activity-Log überführt — symmetrisch zum Passkey-Pfad.
 *
 * Die Tests dispatchen die Laravel-Auth-Events direkt: der Listener-Vertrag ist
 * "Event X → Activity Y", der Auslöser im Framework ist für die Listener-Logik
 * irrelevant. So bleiben die Tests fokussiert und schnell.
 */
final class AuthenticationActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testSuccessfulPasswordLoginIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new Login('web', $user, false));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_login_succeeded')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_login_succeeded'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertFalse($properties['remember'] ?? null);
    }

    public function testPasskeyLoginIsNotDoubleLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        PasskeyLoginContext::markActive();

        try {
            Event::dispatch(new Login('web', $user, false));
        } finally {
            PasskeyLoginContext::clear();
        }

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_login_succeeded')
                ->count(),
            'Passkey-Logins dürfen keinen Passwort-Login-Eintrag erzeugen — '
            . 'der dedizierte Passkey-Eintrag aus recordSuccessfulLoginActivity() deckt diesen Fall ab.',
        );
    }

    /**
     * `Login::$user` ist als `Authenticatable` getypt — ein
     * Drittanbieter-Guard könnte ein Nicht-Eloquent-`Authenticatable`
     * liefern (z. B. `Illuminate\Auth\GenericUser`). Spatie kann diese
     * nicht als `causedBy`/`performedOn` referenzieren — der Listener
     * verlässt früh und schreibt nichts, statt einen halbvollständigen
     * Eintrag zu produzieren.
     */
    public function testLoginWithNonEloquentAuthenticatableIsSilentlySkipped(): void
    {
        Activity::query()->delete();

        $genericUser = new GenericUser(['id' => 1, 'email' => 'x@example.com']);
        Event::dispatch(new Login('web', $genericUser, false));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->count(),
        );
    }

    public function testLogoutWithNonEloquentAuthenticatableIsSilentlySkipped(): void
    {
        Activity::query()->delete();

        $genericUser = new GenericUser(['id' => 1, 'email' => 'x@example.com']);
        Event::dispatch(new Logout('web', $genericUser));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->count(),
        );
    }

    /**
     * Eine reine Whitespace-Eingabe darf keinen `email_hash` produzieren —
     * sonst hätten alle solche Versuche denselben Hash (`sha256("")`-
     * äquivalent), was forensisch wertlos und zugleich irreführend wäre.
     */
    public function testFailedLoginWithWhitespaceOnlyEmailOmitsEmailHash(): void
    {
        Activity::query()->delete();

        Event::dispatch(new Failed('web', null, ['email' => "   \t\n"]));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('email_hash', $properties);
    }

    public function testLogoutIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new Logout('web', $user));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'logout')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_logout'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
    }

    public function testFailedLoginStoresOnlyEmailHashNotPlaintext(): void
    {
        Activity::query()->delete();

        $email = 'opfer@example.com';
        Event::dispatch(new Failed('web', null, ['email' => $email, 'password' => 'wrong']));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_login_failed'), $activity->description);
        $this->assertNull($activity->causer_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertArrayHasKey('email_hash', $properties);
        $this->assertSame(
            hash('sha256', mb_strtolower(trim($email))),
            $properties['email_hash'],
        );
        $this->assertArrayNotHasKey('email', $properties);
        $this->assertArrayNotHasKey('password', $properties);

        // DSGVO-Härtetest: Klartext-Mail darf nirgendwo im serialisierten
        // Eintrag auftauchen — auch nicht in `description` oder anderen Feldern.
        $this->assertStringNotContainsString($email, json_encode($activity->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testFailedLoginWithoutEmailCredentialOmitsEmailHash(): void
    {
        Activity::query()->delete();

        Event::dispatch(new Failed('web', null, ['username' => 'foo']));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('email_hash', $properties);
    }

    public function testLockoutIsLogged(): void
    {
        Activity::query()->delete();

        $request = Request::create('/login', 'POST', ['email' => 'bot@example.com']);
        Event::dispatch(new Lockout($request));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_login_locked_out'), $activity->description);
        $this->assertNull($activity->causer_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('email_hash', $properties);
        $this->assertSame(
            hash('sha256', 'bot@example.com'),
            $properties['email_hash'],
        );
        $this->assertArrayNotHasKey('email', $properties);
    }

    public function testLockoutWithoutEmailInputOmitsEmailHash(): void
    {
        Activity::query()->delete();

        $request = Request::create('/login', 'POST', []);
        Event::dispatch(new Lockout($request));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('email_hash', $properties);
    }

    public function testPasswordUpdatedViaControllerIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new PasswordUpdatedViaController($user));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_updated'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    public function testPasswordResetIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new PasswordReset($user));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_reset')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_reset'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Den Database-Driver-Pfad (Happy + Wrong-Password) lässt sich hier nicht
     * sinnvoll durch `Livewire::test` simulieren: Livewire erstellt intern
     * einen eigenen Request, dem die Session-Middleware nichts zuweist, weil
     * `phpunit.xml` `SESSION_DRIVER=array` erzwingt. Eine vollständige Setup-
     * Reproduktion wäre brüchig. Der Happy-Path ist produktiv über
     * `profile/show.blade.php` plus Code-Review abgedeckt; hier prüfen wir die
     * sicherheitsrelevante Negativ-Garantie: bei nicht-DB-Driver darf KEIN
     * Activity-Eintrag entstehen.
     */
    public function testLogoutOtherBrowserSessionsWithArrayDriverDoesNotLog(): void
    {
        // Mit array-Driver terminiert der Parent gar keine Session — daher
        // darf auch kein Activity-Eintrag entstehen.
        Config::set('session.driver', 'array');

        $user = User::factory()->create();
        $this->actingAs($user);

        Activity::query()->delete();

        Livewire::test(LogoutOtherBrowserSessionsForm::class)
            ->set('password', 'password')
            ->call('logoutOtherBrowserSessions')
            ->assertSuccessful();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'other_sessions_logged_out')
                ->count(),
        );
    }

    /**
     * Self-Delete schreibt einen anonymisierten Audit-Eintrag (DSGVO-Symmetrie:
     * Art. 32 vs. Art. 17). Form: log_name=auth, event=account_self_deleted,
     * KEIN Causer, KEIN Subject, keine personenbezogenen Properties — sonst
     * würde der Purge-Block in `DeleteUser` ihn mit erfassen.
     */
    public function testAccountSelfDeletionWritesAnonymisedAuditEntry(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        app(DeleteUser::class)->delete($user);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'account_self_deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_account_self_deleted'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame([], $properties);
    }

    /**
     * Stellt sicher, dass der anonymisierte Audit-Eintrag den Purge-Block in
     * `DeleteUser` überlebt — er ist die zentrale Brücke zwischen DSGVO Art. 17
     * (Recht auf Vergessen) und Art. 32 (Nachvollziehbarkeit). Würde der Purge
     * jemals zu aggressiv werden (z. B. ein blindes `WHERE log_name='auth'`),
     * fiele dieser Test sofort aus.
     */
    public function testAccountSelfDeletionAuditEntrySurvivesPurge(): void
    {
        $user = User::factory()->create(['name' => 'Vor Löschung']);
        $user->updateOrFail(['name' => 'Nach Umbenennung']);

        $this->assertGreaterThan(
            0,
            Activity::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->count(),
            'Setup-Annahme verletzt: es sollten Subject-Einträge existieren, sonst testet der Purge nichts.',
        );

        app(DeleteUser::class)->delete($user);

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'account_self_deleted')
                ->count(),
        );

        $this->assertSame(
            0,
            Activity::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->count(),
        );
    }

    public function testPasswordUpdateWithNonEloquentAuthenticatableIsSilentlySkipped(): void
    {
        Activity::query()->delete();

        $genericUser = new GenericUser(['id' => 1, 'email' => 'x@example.com']);
        Event::dispatch(new PasswordReset($genericUser));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->count(),
        );
    }

    /**
     * Schützt die vier neuen Übersetzungs-Schlüssel: fehlt einer, würde
     * Laravel den Key wörtlich zurückgeben und der Maschinen-Code landete
     * sichtbar in der UI. Beide Locales prüfen — symmetrisch zum
     * `PasskeyCredentialActivityLogTest`.
     */
    public function testTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'app.activity_password_login_succeeded',
            'app.activity_logout',
            'app.activity_login_failed',
            'app.activity_login_locked_out',
            'app.activity_password_updated',
            'app.activity_password_reset',
            'app.activity_other_sessions_logged_out',
            'app.activity_account_self_deleted',
        ];

        foreach ($keys as $key) {
            foreach (['de', 'en'] as $locale) {
                $this->assertNotSame(
                    $key,
                    Lang::get($key, [], $locale),
                    sprintf("Übersetzungs-Schlüssel '%s' fehlt in Locale '%s'.", $key, $locale),
                );
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Marker zwischen Tests sauber halten — statisches Feld überlebt
        // sonst über die Test-Grenze hinweg.
        PasskeyLoginContext::clear();
    }
}
