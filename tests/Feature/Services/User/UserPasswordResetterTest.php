<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use App\Services\User\Contracts\UserPasswordResetterContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Direkt-Test des Service-Vertrags; `ResetPasswordCommandTest` deckt den Dialog
 * des Kommandos ab.
 */
final class UserPasswordResetterTest extends TestCase
{
    use RefreshDatabase;

    private UserPasswordResetterContract $resetter;

    /**
     * Ein Admin, der an einem fremden Konto handelt, hinterlässt entweder Passwort
     * samt Einträgen oder nichts — ein gespeichertes Passwort ohne Eintrag wäre
     * genau die Spur, die dem Audit fehlt, und der abgebrochene Befehl ließe den
     * Admin raten, was davon gegolten hat.
     */
    public function testAFailingAuditWriteLeavesPasswordAndSwitchAlone(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Schema::drop('activity_log');

        $this->assertThrows(
            fn () => $this->resetter->reset($user, 'new-password123', true),
            QueryException::class,
        );

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->isPasswordLoginDisabled());
        $this->assertTrue(Hash::check('password', $fresh->password));
    }

    /**
     * Die Instanz des Aufrufers stammt vom Beginn des Dialogs — schaltet der
     * Nutzer den Passwort-Login in der Zwischenzeit ab, sieht sie ihn noch offen.
     * Entschieden werden muss auf der gesperrten Zeile, sonst ginge der Login
     * ohne Eintrag wieder auf.
     */
    public function testTheEntryFollowsTheLockedRowNotThePassedInstance(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();
        app(LoginMethodChangerContract::class)->disablePasswordLogin($user);
        Activity::query()->delete();

        $this->resetter->reset($user, 'new-password123', true);

        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'auth')->where('event', 'password_login_enabled')->count(),
        );
        $this->assertFalse($user->fresh()?->isPasswordLoginDisabled());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetter = app(UserPasswordResetterContract::class);
    }
}
