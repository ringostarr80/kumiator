<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class CleanupPendingEmailChangesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testCommandClearsExpiredEntriesAndWritesTtlAudit(): void
    {
        $stale = $this->seedPendingChange(
            User::factory()->create(['email' => 'stale@example.com']),
            'neu-stale@example.com',
            Carbon::now()->subMinutes(120),
        );

        $fresh = $this->seedPendingChange(
            User::factory()->create(['email' => 'fresh@example.com']),
            'neu-fresh@example.com',
            Carbon::now()->subMinutes(5),
        );

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

    private function seedPendingChange(User $user, string $pendingEmail, Carbon $sentAt): User
    {
        $plainToken = bin2hex(random_bytes(32));
        $user->forceFill([
            'pending_email' => $pendingEmail,
            'pending_email_token_hash' => hash('sha256', $plainToken),
            'pending_email_sent_at' => $sentAt,
        ])->saveOrFail();

        return $user;
    }
}
