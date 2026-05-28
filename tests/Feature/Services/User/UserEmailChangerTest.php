<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\User;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\VerifyEmailChangeNotification;
use App\Services\Audit\AuditEmailHasher;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Exceptions\EmailChangeConflictException;
use App\Services\User\Exceptions\EmailChangeTargetNotEligibleException;
use App\Services\User\Exceptions\EmailChangeTokenExpiredException;
use App\Services\User\Exceptions\EmailChangeTokenInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Direkt-Test des Service-Vertrags. Die HTTP-Schicht
 * (`ConfirmEmailChangeControllerTest` / `CancelEmailChangeControllerTest`)
 * deckt denselben Pfad End-to-End ab — dieser Test isoliert die Service-
 * Mechanik (Token-Hash, TTL, Audit, Notification-Versand).
 */
final class UserEmailChangerTest extends TestCase
{
    use RefreshDatabase;

    private UserEmailChangerContract $service;

    public function testRequestChangePersistsPendingFieldsAndSendsBothMails(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Alt',
            'email' => 'alt@example.com',
        ]);
        Activity::query()->delete();

        $this->service->requestChange($user, 'neu@example.com');

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Die kanonischen Pfade bleiben unberührt — DAS ist der Sinn des
        // zweistufigen Verfahrens.
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertNotNull($refreshed->email_verified_at);

        $this->assertSame('neu@example.com', $refreshed->pending_email);
        $this->assertNotNull($refreshed->pending_email_token_hash);
        $this->assertSame(64, strlen((string)$refreshed->pending_email_token_hash));
        $this->assertNotNull($refreshed->pending_email_sent_at);

        $mail = 'neu@example.com';
        Notification::assertSentTo(
            new AnonymousNotifiable(),
            VerifyEmailChangeNotification::class,
            static fn ($n, $c, AnonymousNotifiable $r): bool => ($r->routes['mail'] ?? null) === $mail,
        );
        Notification::assertSentTo($refreshed, EmailChangeRequestedNotification::class);
    }

    public function testRequestChangeWritesEmailChangeRequestedAuditWithPendingEmailHash(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'alt@example.com']);
        Activity::query()->delete();

        $this->service->requestChange($user, 'neu@example.com');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_change_requested'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame(AuditEmailHasher::hash('neu@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
        $this->assertArrayNotHasKey('pending_email_token_hash', $properties);
    }

    public function testRequestChangeOverwritesPreviousPendingTokenInvalidation(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->service->requestChange($user, 'erste@example.com');
        $firstHash = $user->fresh()?->pending_email_token_hash;
        $this->assertNotNull($firstHash);

        $this->service->requestChange($user, 'zweite@example.com');
        $secondHash = $user->fresh()?->pending_email_token_hash;

        $this->assertNotNull($secondHash);
        $this->assertNotSame($firstHash, $secondHash);
        $this->assertSame('zweite@example.com', $user->fresh()->pending_email);
    }

    public function testConfirmChangeSwapsEmailAndWritesAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'alt@example.com',
            'email_verified_at' => Carbon::now()->subDay(),
        ]);
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');
        Activity::query()->delete();

        $returned = $this->service->confirmChange($plainToken);

        $this->assertSame($user->getKey(), $returned->getKey());

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('neu@example.com', $refreshed->email);
        $this->assertNotNull($refreshed->email_verified_at);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_token_hash);
        $this->assertNull($refreshed->pending_email_sent_at);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_changed'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);

        // Bewusst keine Properties: Subject und Causer zeigen beide auf den
        // User selbst, die alte Adresse ist datenminimierend nicht zusätzlich
        // 365 Tage festzuhalten.
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('old_email', $properties);
        $this->assertSame([], $properties);
    }

    public function testConfirmChangeWithUnknownTokenThrowsAndWritesNoAudit(): void
    {
        Notification::fake();
        Activity::query()->delete();

        $this->expectException(EmailChangeTokenInvalidException::class);

        try {
            $this->service->confirmChange(str_repeat('0', 64));
        } finally {
            $this->assertSame(0, Activity::query()->where('log_name', 'auth')->count());
        }
    }

    public function testConfirmChangeWithExpiredTokenThrowsAndClearsPendingFields(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $plainToken = $this->seedPendingChange($user, 'neu@example.com', Carbon::now()->subMinutes(61));
        Activity::query()->delete();

        try {
            $this->service->confirmChange($plainToken);
            $this->fail('Expected EmailChangeTokenExpiredException');
        } catch (EmailChangeTokenExpiredException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_token_hash);
        $this->assertNull($refreshed->pending_email_sent_at);

        $this->assertSame(
            0,
            Activity::query()->where('event', 'email_changed')->count(),
            'Bei abgelaufenem Token darf kein email_changed entstehen.',
        );

        $cancelled = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();
        $this->assertNotNull($cancelled);
        $this->assertNull($cancelled->causer_id);
        $this->assertSame($user->getKey(), $cancelled->subject_id);

        $properties = $cancelled->properties?->toArray() ?? [];
        $this->assertSame('expired_on_confirm', $properties['cancelled_via'] ?? null);
        $this->assertSame(AuditEmailHasher::hash('neu@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testConfirmChangeForTrashedUserThrowsTargetNotEligibleAndWritesRejectedAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');
        $user->deleteOrFail();
        Activity::query()->delete();

        try {
            $this->service->confirmChange($plainToken);
            $this->fail('Expected EmailChangeTargetNotEligibleException');
        } catch (EmailChangeTargetNotEligibleException) {
            // expected
        }

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_confirmation_rejected')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_email_change_confirmation_rejected'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('target_not_eligible', $properties['reason'] ?? null);

        // Bei target_not_eligible bleibt der Pending-State erhalten — der
        // Account ist nur nicht mehr eligible, nichts wird bereinigt.
        $this->assertSame(
            0,
            Activity::query()->where('event', 'email_change_cancelled')->count(),
        );
    }

    public function testConfirmChangeWithConflictThrowsAndClearsPendingFields(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'belegt@example.com']);
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $plainToken = $this->seedPendingChange($user, 'belegt@example.com');
        Activity::query()->delete();

        try {
            $this->service->confirmChange($plainToken);
            $this->fail('Expected EmailChangeConflictException');
        } catch (EmailChangeConflictException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_token_hash);

        $cancelled = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();
        $this->assertNotNull($cancelled);
        $this->assertNull($cancelled->causer_id);
        $this->assertSame($user->getKey(), $cancelled->subject_id);

        $properties = $cancelled->properties?->toArray() ?? [];
        $this->assertSame('target_taken_on_confirm', $properties['cancelled_via'] ?? null);
        $this->assertSame(AuditEmailHasher::hash('belegt@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testCancelChangeClearsPendingAndWritesAnonymousAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');
        Activity::query()->delete();

        $this->service->cancelChange($plainToken);

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertNull($refreshed->pending_email);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame(AuditEmailHasher::hash('neu@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertSame('recipient_revoked', $properties['cancelled_via'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testCancelChangeWithUnknownTokenIsNoOp(): void
    {
        Activity::query()->delete();

        $this->service->cancelChange(str_repeat('0', 64));

        $this->assertSame(0, Activity::query()->count());
    }

    public function testCancelExpiredClearsOnlyExpiredEntriesAndWritesAudit(): void
    {
        Notification::fake();

        $stale = User::factory()->create();
        $this->seedPendingChange($stale, 'stale@example.com', Carbon::now()->subMinutes(120));

        $fresh = User::factory()->create();
        $this->seedPendingChange($fresh, 'fresh@example.com', Carbon::now()->subMinutes(5));

        Activity::query()->delete();

        $count = $this->service->cancelExpired();

        $this->assertSame(1, $count);
        $this->assertNull($stale->fresh()?->pending_email);
        $this->assertSame('fresh@example.com', $fresh->fresh()?->pending_email);

        $activity = Activity::query()
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();
        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('ttl_expired', $properties['cancelled_via'] ?? null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UserEmailChangerContract::class);
    }

    /**
     * Hilfs-Seeder, der das Persistieren via Service umgeht — wir wollen den
     * Service-Pfad NICHT bei jedem Vorbedingungs-Setup mit-testen. Liefert
     * den Klartext-Token zurück, der über Hash wieder auflösbar ist.
     */
    private function seedPendingChange(User $user, string $pendingEmail, ?Carbon $sentAt = null): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $user->forceFill([
            'pending_email' => $pendingEmail,
            'pending_email_token_hash' => hash('sha256', $plainToken),
            'pending_email_sent_at' => $sentAt ?? Carbon::now(),
        ])->saveOrFail();

        return $plainToken;
    }
}
