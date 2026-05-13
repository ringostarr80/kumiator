<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class ConfirmEmailChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testValidTokenSwapsEmailAndShowsConfirmedView(): void
    {
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');

        $response = $this->get(route('email.change.confirm', ['token' => $plainToken]));

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
        $plainToken = $this->seedPendingChange($user, 'neu@example.com', Carbon::now()->subHours(2));

        $response = $this->get(route('email.change.confirm', ['token' => $plainToken]));

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
        $plainToken = $this->seedPendingChange($user, 'belegt@example.com');

        $response = $this->get(route('email.change.confirm', ['token' => $plainToken]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_conflict_message'));

        $refreshed = $user->fresh();
        $this->assertSame('alt@example.com', $refreshed?->email);
        $this->assertNull($refreshed->pending_email);
    }

    public function testTrashedUserShowsInvalidViewToAvoidExistenceLeak(): void
    {
        $user = User::factory()->create();
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');
        $user->deleteOrFail();

        $response = $this->get(route('email.change.confirm', ['token' => $plainToken]));

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
        $plainToken = $this->seedPendingChange($user, 'neu@example.com');

        $first = $this->get(route('email.change.confirm', ['token' => $plainToken]));
        $first->assertOk();

        $second = $this->get(route('email.change.confirm', ['token' => $plainToken]));
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
