<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Tests\Support\SeedsPendingEmailChange;
use Tests\TestCase;

final class CleanupPendingEmailChangesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPendingEmailChange;

    public function testCommandClearsExpiredEntriesAndWritesTtlAudit(): void
    {
        $stale = User::factory()->create(['email' => 'stale@example.com']);
        $this->seedPendingChange($stale, 'neu-stale@example.com', Carbon::now()->subMinutes(120));

        $fresh = User::factory()->create(['email' => 'fresh@example.com']);
        $this->seedPendingChange($fresh, 'neu-fresh@example.com', Carbon::now()->subMinutes(5));

        Activity::query()->delete();

        $this->runCommand()
            ->expectsOutputToContain('1')
            ->assertSuccessful();

        $refreshedStale = $stale->fresh();
        $refreshedFresh = $fresh->fresh();
        $this->assertNotNull($refreshedStale);
        $this->assertNotNull($refreshedFresh);
        $this->assertNull($refreshedStale->pending_email);
        $this->assertSame('neu-fresh@example.com', $refreshedFresh->pending_email);

        $audit = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $properties = $audit->properties?->toArray() ?? [];
        $this->assertSame('ttl_expired', $properties['cancelled_via'] ?? null);
    }

    public function testCommandIsNoOpWhenNothingExpired(): void
    {
        $this->seedPendingChange(
            User::factory()->create(),
            'neu@example.com',
            Carbon::now()->subMinutes(5),
        );

        Activity::query()->delete();

        $this->runCommand()
            ->expectsOutputToContain(__('commands.cleanup_pending_email_changes.none'))
            ->assertSuccessful();

        $this->assertSame(
            0,
            Activity::query()->count(),
            'Ohne abgelaufene Datensätze darf der Cleanup-Lauf KEINEN Audit-Eintrag erzeugen.',
        );
    }

    private function runCommand(): PendingCommand
    {
        $command = $this->artisan('user:cleanup-pending-email-changes');

        if (!$command instanceof PendingCommand) {
            $this->fail('Expected artisan() to return PendingCommand for assertion chaining.');
        }

        return $command;
    }
}
