<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class VerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TEST_EMAIL = 'john@example.com';
    private const string TEST_NAME = 'John Doe';

    public function testUserCanBeVerified(): void
    {
        User::factory()->unverified()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(self::TEST_NAME)
            ->assertSuccessful()
            ->run();

        $user = User::where('email', self::TEST_EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * CLI-Verifizierung schreibt einen anonymisierten Audit-Eintrag
     * (`auth/email_verified_via_cli`) — Subject = User, Causer = null. Der
     * Self-Verify-Pfad über `VerifyEmailController` würde dagegen
     * `auth/email_verified` schreiben; die Trennung der Event-Codes ist
     * bewusst, damit Reports zwischen User-Self-Verify und Admin-Verify
     * unterscheiden können.
     */
    public function testCliVerifyWritesAnonymisedAuditEntry(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        Activity::query()->delete();

        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verified_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_verified_via_cli'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        // Symmetrie-Garantie: KEIN paralleler Self-Verify-Eintrag (`Verified`-
        // Event darf der Command nicht zusätzlich feuern, sonst entstünden
        // zwei semantisch unterschiedliche Einträge für denselben Vorgang).
        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'email_verified')
                ->count(),
        );
    }

    public function testCliVerifyDoesNotProduceGenericUserUpdatedEntry(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => self::TEST_EMAIL,
        ]);

        Activity::query()->delete();

        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->assertSuccessful()
            ->run();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->where('event', 'updated')
                ->count(),
            'Die CLI-Verifizierung darf nur als auth/email_verified_via_cli '
            . 'erscheinen — `email_verified_at` ist absichtlich nicht in '
            . 'User::getActivitylogOptions() enthalten.',
        );
    }

    public function testAlreadyVerifiedUserShowsWarning(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain(
                __('commands.verify_user.already_verified', [
                    'name' => self::TEST_NAME,
                    'email' => self::TEST_EMAIL,
                ]),
            )
            ->assertSuccessful()
            ->run();
    }

    public function testVerifyNonExistentUserFails(): void
    {
        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }
}
