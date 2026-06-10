<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\Activity;
use App\Models\User;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\VerifyEmailChangeNotification;
use App\Services\Audit\AuditEmailHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-End: Profilform → Service → Confirm/Cancel-Route. Stellt sicher,
 * dass der zweistufige Lebenszyklus drei distinkte Activity-Log-Events
 * erzeugt (request → confirmed | cancelled), und dass ein reiner Name-
 * Change KEINE dieser Spuren auslöst.
 */
final class EmailChangeActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private string $capturedToken = '';

    public function testProfileFormEmailChangeWritesRequestedAuditAndLeavesEmailUntouched(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Alt',
            'email' => 'alt@example.com',
        ]);
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => 'Alt',
                'email' => 'neu@example.com',
                'current_password' => 'password',
            ])
            ->call('updateProfileInformation');

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertSame('neu@example.com', $refreshed->pending_email);
        $this->assertNotNull($refreshed->email_verified_at);

        Notification::assertSentTo($refreshed, EmailChangeRequestedNotification::class);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_requested')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame(AuditEmailHasher::hash('neu@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);
    }

    public function testFullHappyPathRequestThenConfirmProducesBothAudits(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'alt@example.com',
        ]);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => 'neu@example.com',
                'current_password' => 'password',
            ])
            ->call('updateProfileInformation');

        $token = $this->extractTokenFromVerifyNotification($user->fresh());

        $this->get(route('email.change.confirm', ['token' => $token]))->assertOk();

        $this->assertSame('neu@example.com', $user->fresh()?->email);

        $events = Activity::query()
            ->where('log_name', 'auth')
            ->whereIn('event', ['email_change_requested', 'email_changed'])
            ->orderBy('id')
            ->pluck('event')
            ->toArray();
        $this->assertSame(['email_change_requested', 'email_changed'], $events);
    }

    public function testFullCancelPathProducesRequestedAndCancelledAudits(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $this->actingAs($user);

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => 'neu@example.com',
                'current_password' => 'password',
            ])
            ->call('updateProfileInformation');

        $token = $this->extractTokenFromVerifyNotification($user->fresh());

        $this->get(route('email.change.cancel', ['token' => $token]))->assertOk();

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertNull($refreshed->pending_email);

        $events = Activity::query()
            ->where('log_name', 'auth')
            ->whereIn('event', ['email_change_requested', 'email_change_cancelled'])
            ->orderBy('id')
            ->pluck('event')
            ->toArray();
        $this->assertSame(['email_change_requested', 'email_change_cancelled'], $events);

        $cancelled = Activity::query()
            ->where('event', 'email_change_cancelled')
            ->latest('id')
            ->first();
        $this->assertNotNull($cancelled);
        // Cancel via Alt-Adress-Link ist anonymisierter Causer (Hijack-Schutz).
        $this->assertNull($cancelled->causer_id);
    }

    public function testProfileFormNameOnlyChangeDoesNotWriteAnyEmailChangeEntry(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'name' => 'Alt',
            'email' => 'gleich@example.com',
        ]);
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => 'Neu', 'email' => 'gleich@example.com'])
            ->call('updateProfileInformation');

        $emailEvents = Activity::query()
            ->where('log_name', 'auth')
            ->whereIn('event', ['email_change_requested', 'email_changed', 'email_change_cancelled'])
            ->count();
        $this->assertSame(0, $emailEvents);

        Notification::assertNothingSent();
    }

    public function testWrongCurrentPasswordWritesRequestFailedAuditAndNoRequestedEntry(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => 'neu@example.com',
                'current_password' => 'falsches-passwort',
            ])
            ->call('updateProfileInformation')
            ->assertHasErrors('current_password');

        $entry = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'email_change_request_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(__('app.activity_email_change_request_failed'), $entry->description);
        $this->assertSame($user->getKey(), $entry->causer_id);
        $this->assertSame('user', $entry->causer_type);
        $this->assertSame($user->getKey(), $entry->subject_id);
        $this->assertSame('user', $entry->subject_type);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame('current_password_mismatch', $properties['failure_reason'] ?? null);
        $this->assertSame(AuditEmailHasher::hash('neu@example.com'), $properties['pending_email_hash'] ?? null);
        $this->assertArrayNotHasKey('pending_email', $properties);

        // Kein Antrag zustande gekommen → weder requested-Eintrag noch Mails.
        $this->assertSame(
            0,
            Activity::query()->where('event', 'email_change_requested')->count(),
        );
        Notification::assertNothingSent();
    }

    public function testMissingCurrentPasswordWritesNoRequestFailedAudit(): void
    {
        // Der `required`-Verstoß ist ein UX-Eingabefehler ohne Forensik-Signal —
        // auditiert wird nur der Mismatch (analog `password_update_failed`).
        Notification::fake();
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => $user->name, 'email' => 'neu@example.com'])
            ->call('updateProfileInformation')
            ->assertHasErrors('current_password');

        $this->assertSame(
            0,
            Activity::query()->where('event', 'email_change_request_failed')->count(),
        );
    }

    /**
     * Holt den Klartext-Token aus der zugestellten `VerifyEmailChangeNotification`,
     * indem `toMail()` ausgeführt und die Action-URL geparst wird. Das ist der
     * einzige Weg an den Token zu kommen, ohne den Service-Internals gegenüber
     * dem Test offenzulegen.
     */
    private function extractTokenFromVerifyNotification(?User $user): string
    {
        $this->assertNotNull($user);
        $pendingEmail = $user->pending_email;
        $this->assertNotNull($pendingEmail);

        // Pattern aus PasswordResetTest: gesamtes Capturing INSIDE der Callback,
        // damit kein `use (&$var)` by reference nötig ist (verbietet PHPCS).
        $this->capturedToken = '';
        Notification::assertSentTo(
            new AnonymousNotifiable(),
            VerifyEmailChangeNotification::class,
            function (
                VerifyEmailChangeNotification $notification,
                array $channels,
                AnonymousNotifiable $notifiable,
            ) use ($pendingEmail): bool {
                if (($notifiable->routes['mail'] ?? null) !== $pendingEmail) {
                    return false;
                }

                $url = (string)$notification->toMail()->actionUrl;
                $this->capturedToken = (string)basename(parse_url($url, PHP_URL_PATH) ?: '');

                return true;
            },
        );

        $this->assertNotSame('', $this->capturedToken);

        return $this->capturedToken;
    }
}
