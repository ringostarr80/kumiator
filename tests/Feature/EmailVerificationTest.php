<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const string FEATURE_NOT_ENABLED_MESSAGE = 'Email verification not enabled.';

    public function testEmailVerificationScreenCanBeRendered(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        $user = User::factory()->withPersonalTeam()->unverified()->create();

        $response = $this->actingAs($user)->get('/email/verify');

        $response->assertStatus(200);
    }

    public function testEmailCanBeVerified(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        Event::fake();

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHas('status', __('app.email_verified_pending_approval_message'));
    }

    public function testEmailCanBeVerifiedWithoutBeingLoggedIn(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        // Realszenario: User registriert sich am Desktop, klickt aber den
        // Mail-Link auf dem Smartphone — dort hat er keine aktive Session.
        // Die signierte URL muss trotzdem akzeptiert werden, sonst landet
        // der User in einer Sackgasse (Login wegen `approved_at = null`
        // ohnehin gesperrt).
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->get($verificationUrl);

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
        $response->assertRedirect(route('login', absolute: false));
    }

    public function testAlreadyVerifiedEmailShowsFlashAndRedirectsToLogin(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        Event::fake();

        $user = User::factory()->create(['email_verified_at' => now()]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $response->assertRedirect(route('login', absolute: false));
        $response->assertSessionHas('status', __('app.email_already_verified_message'));
    }

    public function testEmailCanNotVerifiedWithInvalidHash(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        $user = User::factory()->unverified()->create();
        Activity::query()->delete();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
        );

        $response = $this->get($verificationUrl);

        $response->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('hash_mismatch', $properties['reason'] ?? null);
    }

    public function testEmailCanNotVerifiedForNonExistentUser(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        Activity::query()->delete();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => 999_999, 'hash' => sha1('any@example.com')],
        );

        $this->get($verificationUrl)->assertForbidden();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('user_not_found', $properties['reason'] ?? null);
        $this->assertSame(999_999, $properties['attempted_user_id'] ?? null);
    }

    public function testEmailCanNotVerifiedWithoutValidSignature(): void
    {
        if (! Features::enabled(Features::emailVerification())) {
            $this->markTestSkipped(self::FEATURE_NOT_ENABLED_MESSAGE);
        }

        $user = User::factory()->unverified()->create();

        // No URL::temporarySignedRoute — request hits `signed` middleware
        // and gets rejected before reaching the controller.
        $response = $this->get(sprintf('/email/verify/%d/%s', $user->id, sha1($user->email)));

        $response->assertForbidden();
        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }
}
