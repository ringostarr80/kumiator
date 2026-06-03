<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Validiert das Audit für den Self-Service-Resend der Verifizierungs-Mail
 * (`verification.send`): Fortify versendet ohne Audit-Trail, der Override
 * (`ResendEmailVerificationController` → `EmailVerificationResender`) schreibt
 * `auth/email_verification_requested` — das forensische Gegenstück zum
 * abschließenden `email_verified`, symmetrisch zu `password_reset_requested`.
 */
final class EmailVerificationResendActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testResendByUnverifiedUserIsLoggedAndNotificationSent(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        Activity::query()->delete();

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($user, VerifyEmail::class);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_verification_requested'), $activity->description);

        // Authentifizierter Member: causer UND subject sind der User selbst.
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);

        // Datenminimierung: keine Forensik-Properties (anders als beim anonymen
        // password_reset_requested) — der User ist über die Session identifiziert.
        $this->assertSame([], $activity->properties?->toArray() ?? []);
    }

    public function testResendByAlreadyVerifiedUserIsNotLogged(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Activity::query()->delete();

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertRedirect(route('verification.notice'));
        Notification::assertNothingSent();

        $this->assertSame(
            0,
            Activity::query()
                ->where('event', 'email_verification_requested')
                ->count(),
        );
    }

    public function testGuestCannotResendAndNothingIsLogged(): void
    {
        Notification::fake();
        Activity::query()->delete();

        $response = $this->post(route('verification.send'));

        $response->assertRedirect(route('login'));
        Notification::assertNothingSent();

        $this->assertSame(
            0,
            Activity::query()
                ->where('event', 'email_verification_requested')
                ->count(),
        );
    }
}
