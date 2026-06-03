<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\Activity;
use App\Models\User;
use App\Services\User\Contracts\UserApproverContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direkt-Test des Service-Vertrags. Der Command-Test (`ApproveCommandTest`)
 * und `ConsoleActivityLogTest` decken den End-to-End-Pfad inkl. `cli_actor`
 * zusätzlich ab — dieser Test isoliert den Service von der `artisan`-Schicht
 * und fixiert die Vertragszusage für künftige Caller (z. B. ein Admin-UI).
 *
 * Besonderheit gegenüber {@see UserEmailVerifierTest}: Der `user_approved`-
 * Eintrag wird NICHT explizit geschrieben, sondern entsteht über das
 * Auto-Logging (`approved_at` in `User::logOnly`) plus den `Activity::saving`-
 * Hook. Der Test prüft genau diese implizite Audit-Garantie.
 */
final class UserApproverTest extends TestCase
{
    use RefreshDatabase;

    public function testApproveSetsApprovedAtAndWritesUserApprovedAuditEntry(): void
    {
        $user = User::factory()->unapproved()->create();
        Activity::query()->delete();

        app(UserApproverContract::class)->approve($user);

        $this->assertNotNull($user->fresh()?->approved_at);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('event', 'user_approved')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_approved'), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
    }

    /**
     * Eine reine Freischaltung (nur `approved_at` ändert sich) darf NICHT als
     * `user_renamed` durchrutschen — die `mapUpdatedEventName()`-Heuristik muss
     * `approved_at` im Diff erkennen und `user_approved` schreiben.
     */
    public function testApproveDoesNotProduceRenamedEvent(): void
    {
        $user = User::factory()->unapproved()->create();
        Activity::query()->delete();

        app(UserApproverContract::class)->approve($user);

        $this->assertSame(
            0,
            Activity::query()->where('log_name', 'user')->where('event', 'user_renamed')->count(),
        );
        $this->assertSame(
            1,
            Activity::query()->where('log_name', 'user')->where('event', 'user_approved')->count(),
        );
    }
}
