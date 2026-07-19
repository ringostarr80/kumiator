<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Actions\Jetstream\DeleteUser;
use App\Livewire\Profile\ApiTokenManager;
use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use App\Models\Activity;
use App\Models\User;
use App\Services\Auth\Contracts\OtherDeviceLogoutContextContract;
use App\Services\Auth\Contracts\UnapprovedLoginContextContract;
use App\Services\WebAuthn\PasskeyLoginContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Livewire\Livewire;
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

    private const string GENERIC_EMAIL = 'x@example.com';
    private const string VICTIM_EMAIL = 'opfer@example.com';
    private const string UNAPPROVED_EMAIL = 'unapproved@example.com';
    private const string BOT_EMAIL = 'bot@example.com';
    private const string LOGIN_URL_PATH = '/login';
    private const string CLIENT_IP = '203.0.113.7';
    private const string USER_AGENT = 'Mozilla/5.0 (TestAgent)';

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

        app(PasskeyLoginContext::class)->markActive();

        try {
            Event::dispatch(new Login('web', $user, false));
        } finally {
            app(PasskeyLoginContext::class)->clear();
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

        $genericUser = new GenericUser(['id' => 1, 'email' => self::GENERIC_EMAIL]);
        Event::dispatch(new Login('web', $genericUser, false));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->count(),
        );
    }

    public function testLogoutWithNonEloquentAuthenticatableIsSilentlySkipped(): void
    {
        Activity::query()->delete();

        $genericUser = new GenericUser(['id' => 1, 'email' => self::GENERIC_EMAIL]);
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
            ->where('log_name', 'forensic')
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

    /**
     * DSGVO-Schutz: Wenn der ausloggende User-Datensatz nicht mehr in der DB
     * existiert (Self-Delete-Flow: `DeleteUser::delete()` läuft, dann ruft
     * `Auth::logout()` das `Logout`-Event auf), darf KEIN `logout`-Eintrag
     * mit `causedBy`/`performedOn` auf diesen User entstehen — sonst landet
     * die Morph-Referenz auf den eben gelöschten User wieder im Log und
     * untergräbt den vorausgegangenen Activity-Log-Purge.
     *
     * Reproduziert exakt Jetstreams `DeleteUserForm`: dort wird die `fresh()`-
     * Kopie hart gelöscht, das anschließende `Logout`-Event bekommt aber die
     * ORIGINAL Auth-Instanz mit `exists === true`. Eine in-memory-Prüfung
     * würde hier durchrutschen — der Listener muss gegen die DB prüfen.
     */
    public function testLogoutOfAlreadyDeletedUserIsNotLogged(): void
    {
        $original = User::factory()->create();
        $original->fresh()?->forceDelete();

        Activity::query()->delete();

        Event::dispatch(new Logout('web', $original));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->where('event', 'logout')->count(),
        );
    }

    public function testFailedLoginStoresOnlyEmailHashNotPlaintext(): void
    {
        Activity::query()->delete();

        $email = self::VICTIM_EMAIL;
        Event::dispatch(new Failed('web', null, ['email' => $email, 'password' => 'wrong']));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
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
            hash_hmac('sha256', mb_strtolower(trim($email)), Config::string('app.key')),
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
            ->where('log_name', 'forensic')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('email_hash', $properties);
    }

    /**
     * Forensik bei anonymen Fehlversuchen: gekürzte IP (`/24`) + User-Agent
     * werden als Properties abgelegt, damit verteilter Brute-Force korrelierbar
     * wird. DSGVO-Datenminimierung — die VOLLE Host-IP darf nirgends im Eintrag
     * stehen, nur das Netz (siehe `AuditIpTruncator`).
     */
    public function testFailedLoginStoresTruncatedIpAndUserAgent(): void
    {
        Activity::query()->delete();

        $this->app->instance('request', Request::create(
            self::LOGIN_URL_PATH,
            'POST',
            ['email' => self::VICTIM_EMAIL],
            server: ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_USER_AGENT' => self::USER_AGENT],
        ));

        Event::dispatch(new Failed('web', null, ['email' => self::VICTIM_EMAIL]));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('203.0.113.0/24', $properties['ip'] ?? null);
        $this->assertSame(self::USER_AGENT, $properties['user_agent'] ?? null);

        $this->assertStringNotContainsString(
            self::CLIENT_IP,
            json_encode($activity->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Der User-Agent-Header ist auf den anonymen Pfaden angreiferkontrolliert
     * und würde ungekürzt die langlebig aufbewahrte Forensik-Tabelle aufblähen.
     * Übergroße Header werden daher hart auf 255 Zeichen gekappt.
     */
    public function testFailedLoginTruncatesOverlongUserAgent(): void
    {
        Activity::query()->delete();

        $this->app->instance('request', Request::create(
            self::LOGIN_URL_PATH,
            'POST',
            ['email' => self::VICTIM_EMAIL],
            server: ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_USER_AGENT' => str_repeat('A', 500)],
        ));

        Event::dispatch(new Failed('web', null, ['email' => self::VICTIM_EMAIL]));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame(str_repeat('A', 255), $properties['user_agent'] ?? null);
    }

    /**
     * Ein invalider-UTF-8-User-Agent (angreiferkontrolliert auf den anonymen
     * Pfaden) darf den synchronen Forensik-Insert nicht sprengen: Spaties
     * `collection`-Cast serialisiert die Properties per `json_encode`, das an
     * ungültigem UTF-8 `false` liefert und eine `JsonEncodingException` wirft.
     * Im Login-Request wäre das HTTP 500 statt 422, und der `login_failed`-
     * Eintrag ginge verloren — ein Angreifer könnte Brute-Force aus dem
     * Audit-Log heraushalten. Guard für die von allen drei anonymen Pfaden
     * geteilte `forensicProperties()`-Bereinigung.
     */
    public function testFailedLoginSanitizesInvalidUtf8UserAgent(): void
    {
        Activity::query()->delete();

        // `\xC3\x28` ist eine ungültige UTF-8-Sequenz und kurz genug, um den
        // 255-Zeichen-Cap zu umgehen; ohne Bereinigung liefert `json_encode`
        // dafür `false`.
        $this->app->instance('request', Request::create(
            self::LOGIN_URL_PATH,
            'POST',
            ['email' => self::VICTIM_EMAIL],
            server: ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_USER_AGENT' => "Mozilla \xC3\x28 Bot"],
        ));

        Event::dispatch(new Failed('web', null, ['email' => self::VICTIM_EMAIL]));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $activity,
            'Der login_failed-Eintrag muss trotz invaliden User-Agents geschrieben werden.',
        );

        $properties = $activity->properties?->toArray() ?? [];
        $userAgent = $properties['user_agent'] ?? null;
        $this->assertIsString($userAgent);
        $this->assertTrue(
            mb_check_encoding($userAgent, 'UTF-8'),
            'Der gespeicherte User-Agent muss gültiges UTF-8 sein.',
        );
    }

    /**
     * Ohne Request-IP (z. B. CLI-Auth-Versuch) darf weder `ip` noch
     * `user_agent` entstehen — statt eines wertlosen Platzhalters.
     */
    public function testFailedLoginWithoutRequestIpOmitsForensicProperties(): void
    {
        Activity::query()->delete();

        // CLI-Auth-Pfad nachstellen: keine Client-IP und kein User-Agent.
        // `Request::create` setzt sonst defaultweise REMOTE_ADDR=127.0.0.1 und
        // einen Platzhalter-User-Agent ('Symfony').
        $request = Request::create(self::LOGIN_URL_PATH, 'POST', ['email' => self::GENERIC_EMAIL]);
        $request->server->remove('REMOTE_ADDR');
        $request->headers->remove('User-Agent');
        $this->app->instance('request', $request);

        Event::dispatch(new Failed('web', null, ['email' => self::GENERIC_EMAIL]));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('ip', $properties);
        $this->assertArrayNotHasKey('user_agent', $properties);
    }

    /**
     * Passwort-Login eines nicht freigeschalteten Users:
     *   - genau ein `login_unapproved`-Eintrag mit Causer/Subject auf den User
     *     und `email_hash` für Korrelations-Reports
     *   - KEIN paralleler `login_failed`-Eintrag (Marker greift, sonst wäre der
     *     Versuch unter zwei verschiedenen fachlichen Events doppelt gezählt)
     *   - User bleibt ausgeloggt (Fortify-Closure liefert weiter `null`)
     */
    public function testUnapprovedPasswordLoginIsLoggedAsUnapprovedAndSuppressesLoginFailed(): void
    {
        $user = User::factory()->unapproved()->create([
            'email' => self::UNAPPROVED_EMAIL,
        ]);

        Activity::query()->delete();

        $response = $this->post(self::LOGIN_URL_PATH, [
            'email' => self::UNAPPROVED_EMAIL,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_unapproved')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_login_unapproved'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertSame(
            hash_hmac('sha256', self::UNAPPROVED_EMAIL, Config::string('app.key')),
            $properties['email_hash'] ?? null,
        );
        $this->assertArrayNotHasKey('email', $properties);
        $this->assertArrayNotHasKey('password', $properties);

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'forensic')
                ->where('event', 'login_failed')
                ->count(),
            'Bei `login_unapproved` darf der Marker den parallelen `login_failed`-Eintrag '
            . 'unterdrücken — sonst zählen Reports denselben Versuch unter zwei Events.',
        );
    }

    /**
     * Falsches Passwort für einen unapproved-User darf NICHT als
     * `login_unapproved` durchgehen — sonst könnte ein Angreifer am Audit-Log
     * ablesen, ob ein Account existiert UND dessen Status erraten. Erst nach
     * erfolgreichem Hash-Check ist der `login_unapproved`-Pfad zulässig.
     */
    public function testWrongPasswordOnUnapprovedAccountFallsBackToGenericLoginFailed(): void
    {
        User::factory()->unapproved()->create([
            'email' => self::UNAPPROVED_EMAIL,
        ]);

        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => self::UNAPPROVED_EMAIL,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'login_unapproved')
                ->count(),
        );

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'forensic')
                ->where('event', 'login_failed')
                ->count(),
        );
    }

    /**
     * Passkey-Pfad: Symmetrie zum Passwort-Pfad. Wir rufen den Audit-Helfer
     * direkt auf (so wie `PasskeyAuthenticationController::authenticate()` ihn
     * vor dem 401 aufruft) und prüfen, dass der Eintrag mit Causer/Subject
     * und gehashter Email entsteht.
     */
    public function testUnapprovedPasskeyLoginIsLoggedWithCauserAndEmailHash(): void
    {
        $user = User::factory()->unapproved()->create([
            'email' => 'passkey-unapproved@example.com',
        ]);

        Activity::query()->delete();

        app(UnapprovedLoginContextContract::class)->record($user, 'web', $user->email);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_unapproved')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_login_unapproved'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertSame(
            hash_hmac('sha256', 'passkey-unapproved@example.com', Config::string('app.key')),
            $properties['email_hash'] ?? null,
        );
    }

    /**
     * Direktnachweis der Marker-Wirkung: ein nach `markActive()` gefeuertes
     * `Failed`-Event darf KEINEN `login_failed`-Eintrag erzeugen.
     */
    public function testFailedEventIsSuppressedWhenUnapprovedMarkerActive(): void
    {
        Activity::query()->delete();

        app(UnapprovedLoginContextContract::class)->markActive();

        Event::dispatch(new Failed('web', null, ['email' => self::VICTIM_EMAIL]));

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'forensic')
                ->where('event', 'login_failed')
                ->count(),
        );
    }

    /**
     * Octane-Sicherheit: Der Marker lebt im scoped Container-State statt in
     * einem statischen Feld. Long-Running-Worker rufen zwischen zwei Requests
     * `forgetScopedInstances()` auf — ein im Vorgänger-Request gesetzter
     * Marker darf den Folge-Request nicht erreichen, sonst unterdrückte er
     * dort prozessweit echte `login_failed`-Audits.
     */
    public function testUnapprovedMarkerDoesNotSurviveScopeFlush(): void
    {
        app(UnapprovedLoginContextContract::class)->markActive();

        $this->app->forgetScopedInstances();

        $this->assertFalse(app(UnapprovedLoginContextContract::class)->isActive());
    }

    /**
     * `record()` ist reiner Audit-Schreiber: nur der Passwort-Pfad setzt den
     * Marker selbst. Würde `record()` ihn setzen, bliebe er im Passkey-Pfad
     * (dem kein `Failed`-Event folgt) ungeräumt hängen.
     */
    public function testRecordDoesNotSetTheMarker(): void
    {
        $user = User::factory()->unapproved()->create([
            'email' => self::UNAPPROVED_EMAIL,
        ]);

        app(UnapprovedLoginContextContract::class)->record($user, 'web', $user->email);

        $this->assertFalse(app(UnapprovedLoginContextContract::class)->isActive());
    }

    /**
     * Consume-once: nach dem unterdrückten `Failed` muss der Marker geräumt
     * sein, sonst verschluckte er in einem wiederverwendeten Container (z. B.
     * zwei Login-Versuche in einer Methode) den nächsten echten `login_failed`.
     */
    public function testActiveMarkerIsConsumedSoTheNextFailedStillLogs(): void
    {
        Activity::query()->delete();

        app(UnapprovedLoginContextContract::class)->markActive();

        Event::dispatch(new Failed('web', null, ['email' => 'erst@example.com']));
        Event::dispatch(new Failed('web', null, ['email' => 'dann@example.com']));

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'forensic')
                ->where('event', 'login_failed')
                ->count(),
        );
    }

    public function testLockoutIsLogged(): void
    {
        Activity::query()->delete();

        $request = Request::create(self::LOGIN_URL_PATH, 'POST', ['email' => self::BOT_EMAIL]);
        Event::dispatch(new Lockout($request));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_login_locked_out'), $activity->description);
        $this->assertNull($activity->causer_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('email_hash', $properties);
        $this->assertSame(
            hash_hmac('sha256', self::BOT_EMAIL, Config::string('app.key')),
            $properties['email_hash'],
        );
        $this->assertArrayNotHasKey('email', $properties);
    }

    public function testLockoutWithoutEmailInputOmitsEmailHash(): void
    {
        Activity::query()->delete();

        $request = Request::create(self::LOGIN_URL_PATH, 'POST', []);
        Event::dispatch(new Lockout($request));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('email_hash', $properties);
    }

    /**
     * Forensik beim Lockout (#3): gekürzte IPv6 (`/64`) + User-Agent aus dem
     * Event-Request. Die volle Host-Adresse darf nicht im Eintrag stehen.
     */
    public function testLockoutStoresTruncatedIpAndUserAgent(): void
    {
        Activity::query()->delete();

        $request = Request::create(
            self::LOGIN_URL_PATH,
            'POST',
            ['email' => self::BOT_EMAIL],
            server: ['REMOTE_ADDR' => '2001:db8:1:2:3:4:5:6', 'HTTP_USER_AGENT' => 'curl/8.0'],
        );
        Event::dispatch(new Lockout($request));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'login_locked_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('2001:db8:1:2::/64', $properties['ip'] ?? null);
        $this->assertSame('curl/8.0', $properties['user_agent'] ?? null);

        $this->assertStringNotContainsString(
            '2001:db8:1:2:3:4:5:6',
            json_encode($activity->toArray(), JSON_THROW_ON_ERROR),
        );
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

    public function testPasswordResetLinkRequestIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $this->app->instance('request', Request::create(
            '/forgot-password',
            'POST',
            ['email' => $user->email],
            server: ['REMOTE_ADDR' => self::CLIENT_IP, 'HTTP_USER_AGENT' => self::USER_AGENT],
        ));

        Event::dispatch(new PasswordResetLinkSent($user));

        $activity = Activity::query()
            ->where('log_name', 'forensic')
            ->where('event', 'password_reset_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_reset_requested'), $activity->description);

        // Anonymer Anforderer (Gast auf /forgot-password): kein Causer, aber
        // das Subject identifiziert den Betroffenen.
        $this->assertNull($activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('203.0.113.0/24', $properties['ip'] ?? null);
        $this->assertSame(self::USER_AGENT, $properties['user_agent'] ?? null);

        // Volle Host-IP darf nirgends im Eintrag landen (DSGVO-Datenminimierung).
        $this->assertStringNotContainsString(
            self::CLIENT_IP,
            json_encode($activity->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * `PasswordResetLinkSent::$user` ist als `CanResetPassword` getypt — ein
     * Nicht-Eloquent-Träger (theoretisch über einen Custom-User-Provider) lässt
     * sich nicht als `performedOn` referenzieren; der Listener verlässt früh,
     * statt einen subjektlosen Eintrag zu schreiben (Symmetrie zu den
     * `Login`/`Logout`-Skip-Tests).
     */
    public function testPasswordResetLinkRequestForNonEloquentUserIsSkipped(): void
    {
        Activity::query()->delete();

        $nonEloquent = new class implements CanResetPassword {
            public function getEmailForPasswordReset(): string
            {
                // Anonyme Klasse: kein Zugriff auf die `private const` der
                // Testklasse; der Wert ist ohnehin ein Wegwerf-Stub.
                return 'x@example.com';
            }

            public function sendPasswordResetNotification(mixed $token): void
            {
                // No-op: der Stub existiert nur, um den Nicht-Eloquent-Zweig
                // des Listeners zu treffen; es wird nie eine Notification erwartet.
            }
        };

        Event::dispatch(new PasswordResetLinkSent($nonEloquent));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'forensic')->count(),
        );
    }

    public function testEmailVerifiedIsLogged(): void
    {
        $user = User::factory()->unverified()->create();
        Activity::query()->delete();

        Event::dispatch(new Verified($user));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verified')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_verified'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Sicherstellt, dass derselbe Vorgang KEINEN zusätzlichen Eintrag im
     * `user`-Log erzeugt — weder einen generischen `updated` noch einen
     * fachlichen Code wie `user_renamed`, den der channel-agnostische
     * User-Lifecycle-Hook ansonsten aus einem `email_verified_at`-Diff
     * ableiten würde. `email_verified_at` ist bewusst aus
     * `User::getActivitylogOptions()->logOnly()` ausgenommen; der
     * `auth/email_verified`-Eintrag vom `LogAuthenticationActivityListener`
     * ist die einzige Quelle der Wahrheit für die Verifizierung.
     */
    public function testEmailVerificationDoesNotProduceUserLogEntry(): void
    {
        $user = User::factory()->unverified()->create();
        Activity::query()->delete();

        $user->markEmailAsVerified();
        Event::dispatch(new Verified($user));

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->count(),
            'Die E-Mail-Verifizierung darf nur als auth/email_verified erscheinen, '
            . 'nicht zusätzlich als Eintrag im user-Log (weder generisch noch '
            . 'als fachlicher Code wie user_renamed).',
        );
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
     * Native `Auth::logoutOtherDevices()`-Aufrufe (eigene Controller, künftige
     * API, Tinker) sollen über den `OtherDeviceLogout`-Listener im Audit-Log
     * landen — der dedizierte Event-Code `other_devices_logged_out` ist
     * bewusst vom Form-Eintrag (`other_sessions_logged_out`) getrennt, damit
     * Reports beide Pfade unterscheiden können.
     */
    public function testNativeOtherDeviceLogoutEventIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new OtherDeviceLogout('web', $user));

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'other_devices_logged_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_other_devices_logged_out'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
    }

    /**
     * Im Form-Pfad setzt `LogoutOtherBrowserSessionsForm` vor dem Parent-
     * Aufruf den Marker, der Listener muss diesen Vorgang stumm bleiben —
     * sonst entstünde derselbe Vorgang doppelt (einmal als
     * `other_sessions_logged_out` aus dem Form, einmal als
     * `other_devices_logged_out` aus dem Listener).
     */
    public function testOtherDeviceLogoutListenerIsSilencedWhenContextActive(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        app(OtherDeviceLogoutContextContract::class)->markActive();

        try {
            Event::dispatch(new OtherDeviceLogout('web', $user));
        } finally {
            app(OtherDeviceLogoutContextContract::class)->clear();
        }

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'other_devices_logged_out')
                ->count(),
            'Bei aktivem OtherDeviceLogoutContext darf kein Listener-Eintrag '
            . 'entstehen — der Form-Pfad schreibt seinen eigenen Eintrag.',
        );
    }

    /**
     * Wenn das `OtherDeviceLogout`-Event mit einem Nicht-Eloquent-Träger
     * eintrifft (theoretisch möglich, z. B. exotische Custom-Guards), darf
     * der Listener keinen Eintrag schreiben — analog zu `handleLogin`.
     */
    public function testOtherDeviceLogoutIsSkippedForNonEloquentUser(): void
    {
        Activity::query()->delete();

        $nonEloquentUser = new GenericUser(['id' => 1]);

        Event::dispatch(new OtherDeviceLogout('web', $nonEloquentUser));

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'other_devices_logged_out')
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

    /**
     * API-Token-Erstellung wird als sicherheitsrelevanter Vorgang im
     * Activity-Log erfasst (DSGVO Art. 32, Nachvollziehbarkeit von
     * Anmeldeinformationen). Der Klartext-Token darf NICHT in den
     * Properties landen — nur Name, ID und Abilities.
     */
    public function testApiTokenCreationIsLogged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        Activity::query()->delete();

        $component = Livewire::test(ApiTokenManager::class)
            ->set(['createApiTokenForm' => [
                'name' => 'Audit-Test-Token',
                'permissions' => ['read', 'update'],
            ]])
            ->call('createApiToken');

        // Der echte Klartext-Token-Wert, den der User einmalig in der UI sieht.
        // Sanctum generiert ihn als 40-stellige Hex-Zeichenkette pro Aufruf —
        // unmöglich, denselben Wert „zufällig" woanders im Eintrag zu finden.
        $plainTextToken = $component->get('plainTextToken');
        $this->assertIsString($plainTextToken);
        $this->assertNotEmpty($plainTextToken);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'api_token_created')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_api_token_created'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('Audit-Test-Token', $properties['token_name'] ?? null);
        $this->assertIsInt($properties['token_id'] ?? null);
        $this->assertSame(['read', 'update'], $properties['abilities'] ?? null);

        // DSGVO-Härtetest: Der konkrete Klartext-Token-Wert darf NIRGENDS im
        // serialisierten Eintrag stehen — weder in `properties`, `description`,
        // `subject`/`causer`-Feldern noch in irgendeiner anderen Spalte. Der
        // Vergleich auf den echten Wert (statt auf den Property-Namen) macht
        // den Test echt: würde der Listener jemals versehentlich den Token
        // ablegen, schlüge die Assertion sofort an.
        $serialised = json_encode($activity->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($plainTextToken, $serialised);

        // Auch der Sanctum-Hash des Klartext-Tokens (`token`-Spalte auf
        // `personal_access_tokens`) darf hier nicht auftauchen — sonst hätte
        // ein Angreifer mit DB-Lese-Rechten den Hash UND den Audit-Eintrag,
        // und damit die volle Information zur Token-Verifikation.
        $tokenHash = hash('sha256', $plainTextToken);
        $this->assertStringNotContainsString($tokenHash, $serialised);
    }

    /**
     * API-Token-Widerruf wird mit allen für den Audit relevanten Snapshots
     * geloggt — das passiert im Override VOR `parent::deleteApiToken()`,
     * damit Name und Abilities trotz Delete erhalten bleiben.
     */
    public function testApiTokenRevocationIsLogged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $token = $user->tokens()->create([
            'name' => 'Audit-Revoke-Token',
            'token' => Str::random(40),
            'abilities' => ['read', 'delete'],
        ]);

        Activity::query()->delete();

        Livewire::test(ApiTokenManager::class)
            ->set(['apiTokenIdBeingDeleted' => $token->id])
            ->call('deleteApiToken');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'api_token_revoked')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_api_token_revoked'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('Audit-Revoke-Token', $properties['token_name'] ?? null);
        $this->assertSame($token->id, $properties['token_id'] ?? null);
        $this->assertSame(['read', 'delete'], $properties['abilities'] ?? null);
    }

    /**
     * Eine Ability-Änderung an einem bestehenden Token ist eine Rechte-
     * Eskalation an einer vollwertigen Anmeldeinformation und muss auditiert
     * werden (DSGVO Art. 32). Der Eintrag hält die neuen UND die bisherigen
     * Abilities fest, damit der Delta sichtbar ist — sonst zeigte das Log nur
     * den alten `api_token_created`-Zustand und die Eskalation bliebe unsichtbar.
     */
    public function testApiTokenPermissionChangeIsLogged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $token = $user->tokens()->create([
            'name' => 'Audit-Perms-Token',
            'token' => Str::random(40),
            'abilities' => ['read'],
        ]);

        Activity::query()->delete();

        Livewire::test(ApiTokenManager::class)
            ->set(['managingPermissionsFor' => $token])
            ->set(['updateApiTokenForm' => [
                'permissions' => ['read', 'create', 'update', 'delete'],
            ]])
            ->call('updateApiToken');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'api_token_permissions_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $activity,
            'Eine Ability-Änderung an einem bestehenden Token muss einen '
            . '`api_token_permissions_changed`-Eintrag erzeugen — sonst bleibt '
            . 'die Rechte-Eskalation im Audit-Log unsichtbar.',
        );
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('Audit-Perms-Token', $properties['token_name'] ?? null);
        $this->assertSame($token->id, $properties['token_id'] ?? null);
        $this->assertSame(['read', 'create', 'update', 'delete'], $properties['abilities'] ?? null);
        $this->assertSame(['read'], $properties['previous_abilities'] ?? null);
    }

    /**
     * Der UI-Erstellungspfad auditiert erst, nachdem Sanctum den Token bereits
     * persistiert hat. Bricht der Audit-Insert, darf das die abgeschlossene
     * Token-Erstellung nicht als 500 verkleiden — der Fehler wird gemeldet und
     * verschluckt, der Token bleibt bestehen.
     */
    public function testApiTokenCreationSurvivesFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        // Audit-Sink gezielt unbrauchbar machen: Der Insert ins Activity-Log
        // wirft dann, während `personal_access_tokens` intakt bleibt.
        Schema::drop('activity_log');

        Livewire::test(ApiTokenManager::class)
            ->set(['createApiTokenForm' => [
                'name' => 'Resilienz-Token',
                'permissions' => ['read'],
            ]])
            ->call('createApiToken');

        $tokens = $user->fresh()?->tokens;
        $this->assertNotNull($tokens);
        $this->assertCount(1, $tokens);
        Exceptions::assertReported(QueryException::class);
    }

    /**
     * Symmetrisch zur Erstellung: Der UI-Widerruf auditiert nach
     * `parent::deleteApiToken()`, also nachdem der Token schon gelöscht ist.
     * Ein brechender Audit-Insert darf den erfolgreichen Widerruf nicht als 500
     * verkleiden.
     */
    public function testApiTokenRevocationSurvivesFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $token = $user->tokens()->create([
            'name' => 'Resilienz-Revoke-Token',
            'token' => Str::random(40),
            'abilities' => ['read'],
        ]);

        Schema::drop('activity_log');

        Livewire::test(ApiTokenManager::class)
            ->set(['apiTokenIdBeingDeleted' => $token->id])
            ->call('deleteApiToken');

        $tokens = $user->fresh()?->tokens;
        $this->assertNotNull($tokens);
        $this->assertCount(0, $tokens);
        Exceptions::assertReported(QueryException::class);
    }

    public function testPasswordUpdateWithNonEloquentAuthenticatableIsSilentlySkipped(): void
    {
        Activity::query()->delete();

        $genericUser = new GenericUser(['id' => 1, 'email' => self::GENERIC_EMAIL]);
        Event::dispatch(new PasswordReset($genericUser));

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'auth')->count(),
        );
    }
}
