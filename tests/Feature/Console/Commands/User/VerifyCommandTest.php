<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
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
        $this->assertInstanceOf(PendingCommand::class, $command);

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
     * (`auth/email_verified`) — Subject = User, Causer = null. Der
     * Self-Verify-Pfad über `VerifyEmailController` schreibt denselben
     * Event-Code, aber mit Causer = User (via `Verified`-Event-Listener);
     * die Unterscheidung CLI vs. Self-Service steckt im Causer, nicht im
     * Event-Code.
     */
    public function testCliVerifyWritesAnonymisedEmailVerifiedEntry(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        Activity::query()->delete();

        $command = $this->artisan('user:verify');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->assertSuccessful()
            ->run();

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

        // Doppel-Logging-Schutz: der CLI-Service schreibt direkt und dispatcht
        // bewusst KEIN `Verified`-Event (anders als der `SelfEmailVerifier`).
        // Würde jemand künftig ein Dispatch ergänzen, entstünde ein zweiter
        // Eintrag mit `causedBy($user)` aus dem Listener-Pfad — das fixiert
        // dieser Assert: genau EIN `email_verified`-Eintrag pro Verify-Call,
        // mit anonymisiertem Causer.
        $this->assertSame(
            1,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'email_verified')
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
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
        $this->assertInstanceOf(PendingCommand::class, $command);

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
                ->count(),
            'Die CLI-Verifizierung darf nur als auth/email_verified '
            . 'erscheinen — `email_verified_at` ist absichtlich nicht in '
            . 'User::getActivitylogOptions() enthalten, daher darf kein '
            . 'Eintrag im user-Log entstehen (weder generisch noch fachlich).',
        );
    }

    public function testAlreadyVerifiedUserShowsWarning(): void
    {
        User::factory()->create([
            'name' => self::TEST_NAME,
            'email' => self::TEST_EMAIL,
        ]);

        $command = $this->artisan('user:verify');
        $this->assertInstanceOf(PendingCommand::class, $command);

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
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(
                __('commands.common.not_found', ['email' => 'unknown@example.com']),
            )
            ->assertFailed()
            ->run();
    }
}
