<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\Contracts\OtherSessionRevokerContract;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;
use Tests\Support\InsertsSessions;
use Tests\TestCase;

/**
 * Der Ersatz für `SessionGuard::logoutOtherDevices()`, das ohne Klartextpasswort
 * nicht arbeitet. Beide Rückwege ins Konto müssen zugleich enden: die
 * gespeicherte Sitzung und der Recaller-Cookie.
 */
final class OtherSessionRevokerTest extends TestCase
{
    use InsertsSessions;
    use RefreshDatabase;

    public function testItRemovesEveryOtherSessionAndKeepsTheCurrentOne(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        $this->insertSession(Session::getId(), $user->id);
        $this->insertSession('other-device', $user->id);
        $this->insertSession('third-device', $user->id);

        $revoked = app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertSame(2, $revoked);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', Session::getId())->count());
    }

    public function testItLeavesTheSessionsOfOtherAccountsAlone(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->insertSession('mine', $user->id);
        $this->insertSession('theirs', $other->id);

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertSame(1, DB::table('sessions')->where('id', 'theirs')->count());
    }

    /**
     * Ohne neuen Token meldete der Cookie nach dem Wegfall der Sitzung sofort
     * wieder an — `retrieveByToken()` vergleicht ihn stur gegen die Spalte und
     * läuft dabei an jeder Login-Prüfung vorbei.
     */
    public function testItReplacesTheRememberToken(): void
    {
        $user = User::factory()->create(['remember_token' => $stolen = Str::random(60)]);

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertNotSame($stolen, $user->fresh()?->getRememberToken());
    }

    /**
     * Liegen die Sitzungen ausserhalb der Datenbank, bleibt der Recaller-Cookie
     * als Angriffsfläche — den entwertet der Dienst trotzdem.
     */
    public function testItStillReplacesTheTokenWithoutDatabaseSessions(): void
    {
        Config::set('session.driver', 'array');

        $user = User::factory()->create(['remember_token' => $stolen = Str::random(60)]);
        $this->insertSession('untouched', $user->id);

        $revoked = app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertSame(0, $revoked);
        $this->assertSame(1, DB::table('sessions')->where('id', 'untouched')->count());
        $this->assertNotSame($stolen, $user->fresh()?->getRememberToken());
    }

    /**
     * Der Token ist der Rückweg, der weit über die Sitzung hinaus hält und keinen
     * Treiber braucht. Bricht die Sitzungslöschung ab, muss er schon entwertet
     * sein — sonst hinge die einzige treiberunabhängige Schutzwirkung an einem
     * Schritt, der für sie nichts tut.
     */
    public function testItReplacesTheTokenEvenWhenTheSessionDeleteFails(): void
    {
        Config::set('session.driver', 'database');
        Config::set('session.table', 'no_such_table');

        $user = User::factory()->create(['remember_token' => $stolen = Str::random(60)]);

        try {
            app(OtherSessionRevokerContract::class)->revokeFor($user);
            $this->fail('Erwartete QueryException aus der Sitzungslöschung.');
        } catch (QueryException) {
            // erwartet
        }

        $this->assertNotSame($stolen, $user->fresh()?->getRememberToken());
    }

    /**
     * Eine gelöschte Sitzung bei lebendem Cookie sähe aus wie Erfolg und wäre
     * keiner: Der Cookie meldete beim nächsten Request neu an. Scheitert die
     * Rotation, bleibt deshalb auch die Sitzung stehen — beides offen und ein
     * sichtbarer Fehler statt eines Widerrufs, der keiner war.
     */
    public function testItLeavesTheSessionsAloneWhenTheTokenRotationFails(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        $this->insertSession('other-device', $user->id);

        User::saving(function (User $model): void {
            if ($model->isDirty('remember_token')) {
                throw new \RuntimeException('boom beim Remember-Token-Save');
            }
        });

        try {
            app(OtherSessionRevokerContract::class)->revokeFor($user);
            $this->fail('Erwartete RuntimeException aus der Token-Rotation.');
        } catch (\RuntimeException) {
            // erwartet
        }

        $this->assertSame(1, DB::table('sessions')->where('id', 'other-device')->count());
    }

    /**
     * Das handelnde Gerät hat gerade bestätigt. Sein Recaller-Cookie trägt nach
     * der Rotation den alten Token und wäre sonst still tot — nach Ablauf der
     * Sitzung abgemeldet, obwohl „Angemeldet bleiben" gewählt war. Maßstab ist
     * der Cookie, den der Guard bei einer Anmeldung selbst ausstellt.
     */
    public function testItReissuesTheRecallerCookieOfTheCurrentDevice(): void
    {
        $this->freezeTime();

        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $recallerName = $this->recallerName();
        request()->cookies->set($recallerName, 'issued-with-the-old-token');

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $reissued = Cookie::queued($recallerName);
        $this->assertInstanceOf(HttpCookie::class, $reissued);

        Cookie::unqueue($recallerName);
        Auth::login($user, true);
        $reference = Cookie::queued($recallerName);
        $this->assertInstanceOf(HttpCookie::class, $reference);

        $this->assertSame($reference->getValue(), $reissued->getValue());
        $this->assertSame($reference->getExpiresTime(), $reissued->getExpiresTime());
    }

    /**
     * Jeder Profil-Request läuft durch `auth:sanctum`, und die Middleware stellt
     * den Default-Guard für den Rest des Requests auf Sanctums `RequestGuard`
     * um — der kennt weder Recaller-Namen noch Cookie-Hash. Livewire zieht
     * dieselbe Middleware auf seinen Update-Request nach; so kommt jede Aktion
     * eines Profil-Formulars beim Revoker an.
     */
    public function testItReissuesTheRecallerCookieBehindTheSanctumGuard(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $recallerName = $this->recallerName();

        $this->actingAs($user)->get('/user/profile')->assertOk();
        request()->cookies->set($recallerName, 'issued-with-the-old-token');

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertTrue(Cookie::hasQueued($recallerName));
    }

    /**
     * Ohne Passwort-Login gibt es keine Anmeldung mehr, aus der ein
     * Recaller-Cookie stammen könnte — der vorhandene ist einer von damals.
     */
    public function testItDoesNotReissueTheRecallerCookieWithoutPasswordLogin(): void
    {
        $user = User::factory()->create([
            'remember_token' => Str::random(60),
            'password_login_disabled_at' => now(),
        ]);
        $recallerName = $this->recallerName();
        request()->cookies->set($recallerName, 'issued-with-the-old-token');

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertFalse(Cookie::hasQueued($recallerName));
    }

    public function testItIssuesNoRecallerCookieToADeviceThatHasNone(): void
    {
        $user = User::factory()->create();

        app(OtherSessionRevokerContract::class)->revokeFor($user);

        $this->assertFalse(Cookie::hasQueued($this->recallerName()));
    }

    private function recallerName(): string
    {
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard->getRecallerName();
    }
}
