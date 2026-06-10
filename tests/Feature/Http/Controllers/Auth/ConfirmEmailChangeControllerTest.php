<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ConfirmEmailChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testValidTokenSwapsEmailAndShowsConfirmedView(): void
    {
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $tokens = $this->seedPendingChange($user, 'neu@example.com');

        $response = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_confirmed_message'));

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('neu@example.com', $refreshed->email);
        $this->assertNull($refreshed->pending_email);
    }

    public function testExpiredTokenShowsExpiredView(): void
    {
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $tokens = $this->seedPendingChange($user, 'neu@example.com', Carbon::now()->subHours(2));

        $response = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_expired_message'));

        $this->assertSame('alt@example.com', $user->fresh()?->email);
    }

    public function testUnknownTokenShowsInvalidView(): void
    {
        $response = $this->get(route('email.change.confirm', ['token' => str_repeat('0', 64)]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_invalid_message'));
    }

    public function testConflictShowsConflictViewAndClearsPending(): void
    {
        User::factory()->create(['email' => 'belegt@example.com']);
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $tokens = $this->seedPendingChange($user, 'belegt@example.com');

        $response = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_conflict_message'));

        $refreshed = $user->fresh();
        $this->assertSame('alt@example.com', $refreshed?->email);
        $this->assertNull($refreshed->pending_email);
    }

    public function testTrashedUserShowsInvalidViewToAvoidExistenceLeak(): void
    {
        $user = User::factory()->create();
        $tokens = $this->seedPendingChange($user, 'neu@example.com');
        $user->deleteOrFail();

        $response = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));

        $response->assertOk();
        // Bewusst SELBER View wie bei unbekanntem Token — sonst leakt das UI,
        // ob ein Token einem gelöschten Account entsprach.
        $response->assertSeeText(__('app.email_change_invalid_message'));
    }

    public function testSecondClickOnSameTokenLandsInInvalidView(): void
    {
        // Mail-Prefetch durch Antivirus + manueller User-Klick: der erste
        // Aufruf gewinnt, der zweite findet die `pending_email*`-Felder bereits
        // geräumt vor und läuft in den Invalid-Pfad — ohne State zu zerstören
        // (Tausch bereits durchgeführt, nichts mehr zu tun).
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $tokens = $this->seedPendingChange($user, 'neu@example.com');

        $first = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));
        $first->assertOk();

        $second = $this->get(route('email.change.confirm', ['token' => $tokens['confirm']]));
        $second->assertOk();
        $second->assertSeeText(__('app.email_change_invalid_message'));

        // Status nach dem ersten Klick stabil
        $this->assertSame('neu@example.com', $user->fresh()?->email);
    }

    public function testNoConfirmAuditOnUnknownToken(): void
    {
        Activity::query()->delete();

        $this->get(route('email.change.confirm', ['token' => str_repeat('a', 64)]));

        $this->assertSame(0, Activity::query()->where('event', 'email_changed')->count());
    }

    public function testCancelTokenIsRejectedOnConfirmEndpoint(): void
    {
        // Wer nur die Hinweis-Mail an die ALTE Adresse einsehen kann
        // (Mailbox-Zugriff, Link-Scanner-Logs), darf damit nicht bestätigen
        // können. Gleicher Invalid-View wie bei unbekanntem Token (kein
        // Oracle), Pending-State bleibt unberührt.
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $tokens = $this->seedPendingChange($user, 'neu@example.com');

        $response = $this->get(route('email.change.confirm', ['token' => $tokens['cancel']]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_invalid_message'));

        $refreshed = $user->fresh();
        $this->assertSame('alt@example.com', $refreshed?->email);
        $this->assertSame('neu@example.com', $refreshed->pending_email);
    }

    /**
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
}
