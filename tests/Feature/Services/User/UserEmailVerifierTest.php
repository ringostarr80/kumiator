<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\Activity;
use App\Models\User;
use App\Services\User\Contracts\UserEmailVerifierContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direkt-Test des Service-Vertrags. Der Command-Test
 * (`VerifyCommandTest`) deckt denselben Pfad zusätzlich End-to-End
 * ab — dieser Test isoliert den Service von der `artisan`-Schicht
 * und schärft die Vertragszusage für künftige Caller (z. B. ein
 * späteres Admin-UI), das die Audit-Garantie nicht über einen
 * Command-Pfad nachweisen könnte.
 */
final class UserEmailVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function testVerifySetsEmailVerifiedAtAndWritesAnonymisedAuditEntry(): void
    {
        $user = User::factory()->unverified()->create();
        Activity::query()->delete();

        app(UserEmailVerifierContract::class)->verify($user);

        $this->assertNotNull($user->fresh()?->email_verified_at);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verified')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_verified'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Der Service verifiziert bedingungslos — die `hasVerifiedEmail()`-
     * Vorprüfung liegt beim Aufrufer (siehe Doc des Contracts). Falls ein
     * bereits verifizierter User reinkommt, wird `email_verified_at`
     * trotzdem auf jetzt aktualisiert UND ein neuer Audit-Eintrag erzeugt.
     * Der Test fixiert dieses Verhalten als bewusste Vertragszusage,
     * damit künftige Caller wissen, wo die Idempotenz-Logik liegt.
     */
    public function testVerifyIsUnconditionalAndOverwritesPriorTimestamp(): void
    {
        $originalTimestamp = now()->subMonth();
        $user = User::factory()->create(['email_verified_at' => $originalTimestamp]);
        Activity::query()->delete();

        app(UserEmailVerifierContract::class)->verify($user);

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed?->email_verified_at);
        $this->assertTrue($refreshed->email_verified_at->greaterThan($originalTimestamp));

        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'email_verified')
                ->count(),
        );
    }

    /**
     * Wenn ein Test-Setup `actingAs()` aufgerufen hat, liegt ein User in
     * `Auth::user()` — ohne `causedByAnonymous()` würde Spatie's
     * `CauserResolver` diesen User als Default-Causer eintragen. Dieser
     * Test fixiert die Anonymisierung gegen genau diesen Fall.
     */
    public function testVerifyStaysAnonymousEvenWhenAuthUserIsSet(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $target = User::factory()->unverified()->create();
        Activity::query()->delete();

        app(UserEmailVerifierContract::class)->verify($target);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verified')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
    }
}
