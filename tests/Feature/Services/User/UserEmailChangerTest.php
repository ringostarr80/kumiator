<?php

declare(strict_types=1);

namespace Tests\Feature\Services\User;

use App\Models\Activity;
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

    private const string OLD_EMAIL = 'alt@example.com';
    private const string NEW_EMAIL = 'neu@example.com';
    private const string TAKEN_EMAIL = 'belegt@example.com';
    private const string EXPECTED_CONFLICT = 'Expected EmailChangeConflictException';

    private UserEmailChangerContract $service;

    // Capturing INSIDE der assertSentTo-Callback über ein Property, damit
    // kein `use (&$var)` by reference nötig ist (verbietet PHPCS).
    private string $capturedToken = '';

    // Re-Entry-Guard für den Race-Injektions-Hook (gleicher Grund: kein
    // `use (&$var)` by reference erlaubt).
    private bool $raceTargetInjected = false;

    public function testRequestChangePersistsPendingFieldsAndSendsBothMails(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Alt',
            'email' => self::OLD_EMAIL,
        ]);
        Activity::query()->delete();

        $this->service->requestChange($user, self::NEW_EMAIL);

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Die kanonischen Pfade bleiben unberührt — DAS ist der Sinn des
        // zweistufigen Verfahrens.
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertNotNull($refreshed->email_verified_at);

        $this->assertSame(self::NEW_EMAIL, $refreshed->pending_email);
        $this->assertNotNull($refreshed->pending_email_confirm_token_hash);
        $this->assertSame(64, strlen((string)$refreshed->pending_email_confirm_token_hash));
        $this->assertNotNull($refreshed->pending_email_cancel_token_hash);
        $this->assertSame(64, strlen((string)$refreshed->pending_email_cancel_token_hash));
        $this->assertNotSame($refreshed->pending_email_confirm_token_hash, $refreshed->pending_email_cancel_token_hash);
        $this->assertNotNull($refreshed->pending_email_sent_at);

        Notification::assertSentTo(
            new AnonymousNotifiable(),
            VerifyEmailChangeNotification::class,
            static fn ($n, $c, AnonymousNotifiable $r): bool => ($r->routes['mail'] ?? null) === self::NEW_EMAIL,
        );
        Notification::assertSentTo($refreshed, EmailChangeRequestedNotification::class);
    }

    public function testRequestChangeWritesEmailChangeRequestedAuditWithPendingEmailHash(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        Activity::query()->delete();

        $this->service->requestChange($user, self::NEW_EMAIL);

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
        $this->assertSame(AuditEmailHasher::hash(self::NEW_EMAIL), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
        $this->assertArrayNotHasKey('pending_email_confirm_token_hash', $properties);
        $this->assertArrayNotHasKey('pending_email_cancel_token_hash', $properties);
    }

    public function testRequestChangeIssuesActionScopedTokensPerMail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);

        $this->service->requestChange($user, self::NEW_EMAIL);

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        // Jede Mail trägt NUR den Token ihrer eigenen Aktion: Die Cancel-Mail
        // an die alte Adresse darf niemanden zum Bestätigen befähigen
        // (Mailbox-Zugriff, Link-Scanner-Logs).
        $this->capturedToken = '';
        Notification::assertSentTo(
            new AnonymousNotifiable(),
            VerifyEmailChangeNotification::class,
            function (VerifyEmailChangeNotification $notification): bool {
                $this->capturedToken = $this->tokenFromActionUrl((string)$notification->toMail()->actionUrl);

                return true;
            },
        );
        $this->assertSame(hash('sha256', $this->capturedToken), $refreshed->pending_email_confirm_token_hash);

        $plainConfirmToken = $this->capturedToken;

        $this->capturedToken = '';
        Notification::assertSentTo(
            $refreshed,
            EmailChangeRequestedNotification::class,
            function (EmailChangeRequestedNotification $notification) use ($refreshed): bool {
                $this->capturedToken = $this->tokenFromActionUrl(
                    (string)$notification->toMail($refreshed)->actionUrl,
                );

                return true;
            },
        );
        $this->assertSame(hash('sha256', $this->capturedToken), $refreshed->pending_email_cancel_token_hash);
        $this->assertNotSame($plainConfirmToken, $this->capturedToken);
    }

    public function testRequestChangeOverwritesPreviousPendingTokenInvalidation(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->service->requestChange($user, 'erste@example.com');
        $firstConfirmHash = $user->fresh()?->pending_email_confirm_token_hash;
        $firstCancelHash = $user->fresh()?->pending_email_cancel_token_hash;
        $this->assertNotNull($firstConfirmHash);
        $this->assertNotNull($firstCancelHash);

        $this->service->requestChange($user, 'zweite@example.com');
        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);

        $this->assertNotNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNotSame($firstConfirmHash, $refreshed->pending_email_confirm_token_hash);
        $this->assertNotNull($refreshed->pending_email_cancel_token_hash);
        $this->assertNotSame($firstCancelHash, $refreshed->pending_email_cancel_token_hash);
        $this->assertSame('zweite@example.com', $refreshed->pending_email);
    }

    public function testConfirmChangeSwapsEmailAndWritesAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => self::OLD_EMAIL,
            'email_verified_at' => Carbon::now()->subDay(),
        ]);
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL);
        Activity::query()->delete();

        $returned = $this->service->confirmChange($tokens['confirm']);

        $this->assertSame($user->getKey(), $returned->getKey());

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::NEW_EMAIL, $refreshed->email);
        $this->assertNotNull($refreshed->email_verified_at);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNull($refreshed->pending_email_cancel_token_hash);
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
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL, Carbon::now()->subMinutes(61));
        Activity::query()->delete();

        try {
            $this->service->confirmChange($tokens['confirm']);
            $this->fail('Expected EmailChangeTokenExpiredException');
        } catch (EmailChangeTokenExpiredException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNull($refreshed->pending_email_cancel_token_hash);
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
        $this->assertSame(AuditEmailHasher::hash(self::NEW_EMAIL), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testConfirmChangeForTrashedUserThrowsTargetNotEligibleAndWritesRejectedAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL);
        $user->deleteOrFail();
        Activity::query()->delete();

        try {
            $this->service->confirmChange($tokens['confirm']);
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
        User::factory()->create(['email' => self::TAKEN_EMAIL]);
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        $tokens = $this->seedPendingChange($user, self::TAKEN_EMAIL);
        Activity::query()->delete();

        try {
            $this->service->confirmChange($tokens['confirm']);
            $this->fail(self::EXPECTED_CONFLICT);
        } catch (EmailChangeConflictException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNull($refreshed->pending_email_cancel_token_hash);

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
        $this->assertSame(AuditEmailHasher::hash(self::TAKEN_EMAIL), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testConfirmChangeTreatsTrashedHolderOfTargetEmailAsConflict(): void
    {
        Notification::fake();
        // Auch ein soft-gelöschter Halter blockiert den Tausch: er kann per
        // user:restore zurückkommen, und der DB-Unique-Index verbietet das
        // Duplikat ohnehin. Ohne Trashed-Berücksichtigung liefe der Confirm
        // statt in den Conflict-Pfad (View + Audit + Bereinigung) in eine
        // unbehandelte Unique-Verletzung.
        $holder = User::factory()->create(['email' => self::TAKEN_EMAIL]);
        $holder->deleteOrFail();
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        $tokens = $this->seedPendingChange($user, self::TAKEN_EMAIL);
        Activity::query()->delete();

        try {
            $this->service->confirmChange($tokens['confirm']);
            $this->fail(self::EXPECTED_CONFLICT);
        } catch (EmailChangeConflictException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNull($refreshed->pending_email_cancel_token_hash);

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
        $this->assertSame(AuditEmailHasher::hash(self::TAKEN_EMAIL), $properties['pending_email_hash'] ?? null);
    }

    public function testConfirmChangeTranslatesConcurrentUniqueViolationToConflict(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        $tokens = $this->seedPendingChange($user, self::TAKEN_EMAIL);
        Activity::query()->delete();

        // Race deterministisch nachstellen: Erst beim finalen Email-Save (nicht
        // beim Seed darüber) belegt ein paralleler Confirm die Zieladresse —
        // also genau zwischen exists()-Prüfung und Save. Ein echtes zweites
        // Insert, kein Mock. Der Guard verhindert Re-Entry durch das Insert
        // selbst; der Hook leakt nicht, da pro Test der Model-Event-Dispatcher
        // neu gebootet wird.
        User::saving(function (User $model): void {
            if ($this->raceTargetInjected || $model->email !== self::TAKEN_EMAIL) {
                return;
            }

            $this->raceTargetInjected = true;
            User::factory()->create(['email' => self::TAKEN_EMAIL]);
        });

        try {
            $this->service->confirmChange($tokens['confirm']);
            $this->fail(self::EXPECTED_CONFLICT);
        } catch (EmailChangeConflictException) {
            // expected
        }

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertNull($refreshed->pending_email);
        $this->assertNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNull($refreshed->pending_email_cancel_token_hash);

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
        $this->assertSame(AuditEmailHasher::hash(self::TAKEN_EMAIL), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testCancelChangeClearsPendingAndWritesAnonymousAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL);
        Activity::query()->delete();

        $this->service->cancelChange($tokens['cancel']);

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
        $this->assertSame(AuditEmailHasher::hash(self::NEW_EMAIL), $properties['pending_email_hash'] ?? null);
        $this->assertSame('recipient_revoked', $properties['cancelled_via'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testCancelChangeWithUnknownTokenIsNoOp(): void
    {
        Activity::query()->delete();

        $this->service->cancelChange(str_repeat('0', 64));

        $this->assertSame(0, Activity::query()->count());
    }

    public function testConfirmChangeRejectsCancelTokenAndWritesRejectedAudit(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => self::OLD_EMAIL]);
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL);
        Activity::query()->delete();

        try {
            $this->service->confirmChange($tokens['cancel']);
            $this->fail('Expected EmailChangeTokenInvalidException');
        } catch (EmailChangeTokenInvalidException) {
            // expected
        }

        // Der Missbrauchsversuch darf die legitime offene Anfrage weder
        // bestätigen noch abbrechen — der Pending-State bleibt vollständig
        // erhalten.
        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame(self::OLD_EMAIL, $refreshed->email);
        $this->assertSame(self::NEW_EMAIL, $refreshed->pending_email);
        $this->assertNotNull($refreshed->pending_email_confirm_token_hash);
        $this->assertNotNull($refreshed->pending_email_cancel_token_hash);

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
        $this->assertSame('cancel_token_on_confirm', $properties['reason'] ?? null);

        $this->assertSame(0, Activity::query()->where('event', 'email_changed')->count());
        $this->assertSame(0, Activity::query()->where('event', 'email_change_cancelled')->count());
    }

    public function testCancelChangeIgnoresConfirmToken(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $tokens = $this->seedPendingChange($user, self::NEW_EMAIL);
        Activity::query()->delete();

        $this->service->cancelChange($tokens['confirm']);

        // Aktionsbindung in Gegenrichtung: Der Confirm-Token bricht nichts
        // ab — die offene Anfrage bleibt bestehen, kein Audit-Eintrag.
        $this->assertSame(self::NEW_EMAIL, $user->fresh()?->pending_email);
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
     * die Klartext-Tokens zurück, die über Hash wieder auflösbar sind.
     *
     * @return array{confirm: string, cancel: string}
     */
    private function seedPendingChange(User $user, string $pendingEmail, ?Carbon $sentAt = null): array
    {
        $plainConfirmToken = bin2hex(random_bytes(32));
        $plainCancelToken = bin2hex(random_bytes(32));
        $user->forceFill([
            'pending_email' => $pendingEmail,
            'pending_email_confirm_token_hash' => hash('sha256', $plainConfirmToken),
            'pending_email_cancel_token_hash' => hash('sha256', $plainCancelToken),
            'pending_email_sent_at' => $sentAt ?? Carbon::now(),
        ])->saveOrFail();

        return ['confirm' => $plainConfirmToken, 'cancel' => $plainCancelToken];
    }

    private function tokenFromActionUrl(string $url): string
    {
        return (string)basename(parse_url($url, PHP_URL_PATH) ?: '');
    }
}
