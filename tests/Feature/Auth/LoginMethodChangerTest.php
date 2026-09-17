<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\Support\InsertsSessions;
use Tests\TestCase;

final class LoginMethodChangerTest extends TestCase
{
    use InsertsSessions;
    use RefreshDatabase;

    private LoginMethodChangerContract $changer;

    public function testItDisablesThePasswordLoginWhenAPasskeyRemains(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->assertTrue($this->changer->disablePasswordLogin($user));

        $user->refresh();
        $this->assertTrue($user->isPasswordLoginDisabled());
    }

    public function testItLeavesTheSwitchAloneWithoutAPasskey(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->changer->disablePasswordLogin($user));

        $user->refresh();
        $this->assertFalse($user->isPasswordLoginDisabled());
    }

    /**
     * Zu ist der Passwort-Login erst, wenn auch die Wege enden, die aus dem
     * Passwort hervorgingen. Der Recaller-Cookie ist der wichtigste: Sein Pfad
     * vergleicht nur den Token und fragt die Spalte nie.
     */
    public function testDisablingRotatesTheRememberToken(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        PasskeyCredential::factory()->for($user)->create();
        $tokenBefore = $user->getRememberToken();

        $this->changer->disablePasswordLogin($user);

        $this->assertNotSame($tokenBefore, $user->fresh()?->getRememberToken());
    }

    public function testDisablingEndsTheOtherSessionsOfTheAccount(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        $this->insertSession(Session::getId(), $user->id);
        $this->insertSession('other-device', $user->id);

        $this->changer->disablePasswordLogin($user);

        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertSame(1, DB::table('sessions')->where('id', Session::getId())->count());
    }

    public function testDisablingInvalidatesAnOpenPasswordResetToken(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        $token = Password::createToken($user);

        $this->changer->disablePasswordLogin($user);

        $this->assertFalse(Password::tokenExists($user, $token));
    }

    public function testDisablingIsAudited(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        $this->changer->disablePasswordLogin($user);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_login_disabled')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Zwei Aufrufe, die beide noch den offenen Schalter sahen: Die Entscheidung
     * fällt auf der gesperrten Zeile, sonst verschöbe der zweite den Stempel,
     * seit dem das Konto ohne Passwort auskommt, und legte ein zweites Ereignis
     * für einen Vorgang an, der nur einmal stattfand.
     */
    public function testDisablingAgainLeavesTheTimestampAndTheAuditAlone(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();
        $this->changer->disablePasswordLogin($user);

        $this->travelTo(Carbon::parse('2026-01-01 10:00:05'));

        $this->assertTrue($this->changer->disablePasswordLogin($user));

        $this->assertSame('2026-01-01 10:00:00', $user->fresh()?->password_login_disabled_at?->toDateTimeString());
        $this->assertSame(1, Activity::query()->where('event', 'password_login_disabled')->count());
    }

    public function testItEnablesThePasswordLoginAgain(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->assertTrue($this->changer->enablePasswordLogin($user));

        $user->refresh();
        $this->assertFalse($user->isPasswordLoginDisabled());
    }

    public function testItReportsNothingToDoWhenThePasswordLoginIsAlreadyOpen(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->changer->enablePasswordLogin($user));

        $user->refresh();
        $this->assertFalse($user->isPasswordLoginDisabled());
    }

    public function testEnablingIsAudited(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->changer->enablePasswordLogin($user);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'auth')->where('event', 'password_login_enabled')->count(),
        );
    }

    /**
     * Abgeschaltet wird der Passwort-Login, wenn dem Passwort nicht mehr zu trauen
     * ist. Bliebe es beim Wiedereinschalten stehen, nähme der offene Zugang diese
     * Aussage still zurück — derselbe Grund, aus dem der Soft-Delete es ersetzt.
     */
    public function testItDiscardsThePasswordWhenTheLoginIsOpenedAgain(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();

        $this->assertTrue($this->changer->enablePasswordLogin($user));

        $user->refresh();
        $this->assertFalse(Hash::check('password', $user->password));
    }

    /**
     * Der Verdacht gilt dem Passwort von vor dem Abschalten. Was seither gesetzt
     * wurde, kam per Passkey-bestätigter Sitzung oder über die Konsole — und die
     * Konsole verspricht dabei, dass es nach dem Wiedereinschalten gilt.
     */
    public function testItKeepsAPasswordSetAfterTheDisabling(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password = 'seither-gesetzt';
        $user->saveOrFail();

        $this->assertTrue($this->changer->enablePasswordLogin($user));

        $user->refresh();
        $this->assertTrue(Hash::check('seither-gesetzt', $user->password));
    }

    public function testItKeepsThePasswordWhenThereWasNothingToDo(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->changer->enablePasswordLogin($user));

        $user->refresh();
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function testItDeletesTheLastPasskeyWhileThePasswordLoginRemains(): void
    {
        $passkey = PasskeyCredential::factory()->for(User::factory())->create();

        $this->assertTrue($this->changer->deletePasskey($passkey));

        $this->assertModelMissing($passkey);
    }

    public function testItKeepsTheLastPasskeyOfAnAccountWithoutPasswordLogin(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $passkey = PasskeyCredential::factory()->for($user)->create();

        $this->assertFalse($this->changer->deletePasskey($passkey));

        $this->assertModelExists($passkey);
    }

    public function testItDeletesAFurtherPasskeyOfAnAccountWithoutPasswordLogin(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        $kept = PasskeyCredential::factory()->for($user)->create();
        $removed = PasskeyCredential::factory()->for($user)->create();

        $this->assertTrue($this->changer->deletePasskey($removed));

        $this->assertModelMissing($removed);
        $this->assertModelExists($kept);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->changer = app(LoginMethodChangerContract::class);
    }
}
