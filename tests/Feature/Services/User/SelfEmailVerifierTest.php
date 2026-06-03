<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\Activity;
use App\Models\User;
use App\Services\User\Contracts\SelfEmailVerifierContract;
use App\Services\User\Exceptions\SelfEmailVerificationFailedException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Direkt-Test des Service-Vertrags. `EmailVerificationTest` deckt denselben
 * Pfad zusätzlich End-to-End über den HTTP-Endpoint ab — dieser Test
 * isoliert die Service-Mechanik (Lookup, Hash-Check, Verified-Dispatch,
 * Failure-Audits).
 */
final class SelfEmailVerifierTest extends TestCase
{
    use RefreshDatabase;

    private SelfEmailVerifierContract $service;

    public function testVerifySetsEmailVerifiedAtAndDispatchesVerifiedEvent(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $userId = $user->id;

        $returned = $this->service->verify($userId, sha1((string)$user->email));

        $this->assertSame($userId, $returned->id);
        $this->assertNotNull($user->fresh()?->email_verified_at);

        Event::assertDispatched(
            Verified::class,
            static function (Verified $e) use ($userId): bool {
                $verifiedUser = $e->user;

                return $verifiedUser instanceof User && $verifiedUser->id === $userId;
            },
        );
    }

    public function testVerifyWithUnknownUserIdThrowsAndWritesAnonymousFailureAudit(): void
    {
        Activity::query()->delete();

        try {
            $this->service->verify(999_999, sha1('any@example.com'));
            $this->fail('Expected SelfEmailVerificationFailedException');
        } catch (SelfEmailVerificationFailedException) {
            // expected
        }

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_verification_failed'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('user_not_found', $properties['reason'] ?? null);
        $this->assertSame(999_999, $properties['attempted_user_id'] ?? null);
    }

    public function testVerifyWithHashMismatchThrowsAndAuditCarriesPerformedOnUser(): void
    {
        $user = User::factory()->unverified()->create();
        $userId = $user->id;
        Activity::query()->delete();

        try {
            $this->service->verify($userId, sha1('wrong@example.com'));
            $this->fail('Expected SelfEmailVerificationFailedException');
        } catch (SelfEmailVerificationFailedException) {
            // expected
        }

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertSame($userId, $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('hash_mismatch', $properties['reason'] ?? null);
        $this->assertArrayNotHasKey('attempted_user_id', $properties);
    }

    /**
     * Falls ein Test-Setup `actingAs()` aufruft, liegt ein User in
     * `Auth::user()` — ohne `causedByAnonymous()` würde Spatie's
     * `CauserResolver` diesen User als Default-Causer eintragen. Dieser
     * Test fixiert die Anonymisierung der Failure-Audits gegen genau diesen
     * Fall (Symmetrie zu {@see UserEmailVerifierTest::testVerifyStaysAnonymousEvenWhenAuthUserIsSet}).
     */
    public function testFailureAuditStaysAnonymousEvenWhenAuthUserIsSet(): void
    {
        $someone = User::factory()->create();
        $this->actingAs($someone);

        $target = User::factory()->unverified()->create();
        Activity::query()->delete();

        try {
            $this->service->verify($target->id, sha1('wrong@example.com'));
        } catch (SelfEmailVerificationFailedException) {
            // expected
        }

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_verification_failed')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SelfEmailVerifierContract::class);
    }
}
