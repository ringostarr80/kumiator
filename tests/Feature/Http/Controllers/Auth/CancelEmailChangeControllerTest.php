<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\SeedsPendingEmailChange;
use Tests\TestCase;

final class CancelEmailChangeControllerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPendingEmailChange;

    public function testGetShowsLandingPageWithoutSideEffects(): void
    {
        // Die Hinweis-Mail geht an die ALTE Adresse — ein Link-Scanner dort
        // würde per GET jede legitime Änderung abbrechen, bevor der User den
        // Confirm-Link klicken kann. Die Landingpage ist deshalb
        // nebenwirkungsfrei; erst der POST des Formulars bricht ab.
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $plainToken = $this->seedPendingChange($user, 'neu@example.com')['cancel'];
        Activity::query()->delete();

        $response = $this->get(route('email.change.cancel', ['token' => $plainToken]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_cancel_button'));
        $response->assertSee(route('email.change.cancel.perform', ['token' => $plainToken]));

        $this->assertSame('neu@example.com', $user->fresh()?->pending_email);
        $this->assertSame(0, Activity::query()->count());
    }

    public function testValidTokenCancelsPendingChangeAndShowsCancelledView(): void
    {
        $user = User::factory()->create(['email' => 'alt@example.com']);
        $plainToken = $this->seedPendingChange($user, 'neu@example.com')['cancel'];

        $response = $this->post(route('email.change.cancel.perform', ['token' => $plainToken]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_cancelled_message'));

        $refreshed = $user->fresh();
        $this->assertNotNull($refreshed);
        $this->assertSame('alt@example.com', $refreshed->email);
        $this->assertNull($refreshed->pending_email);
    }

    /**
     * Generischer Erfolgs-View auch bei unbekanntem Token: würden wir hier
     * differenzieren, könnte ein Angreifer durch Probieren feststellen, ob
     * eine offene Anfrage zu einem bestimmten Token-Wert existiert.
     */
    public function testUnknownTokenShowsCancelledViewAndIsAuditSilent(): void
    {
        Activity::query()->delete();

        $response = $this->post(route('email.change.cancel.perform', ['token' => str_repeat('0', 64)]));

        $response->assertOk();
        $response->assertSeeText(__('app.email_change_cancelled_message'));

        $this->assertSame(
            0,
            Activity::query()->where('event', 'email_change_cancelled')->count(),
            'Unbekannter Token darf KEINEN Audit-Eintrag erzeugen — sonst nutzbarer Oracle.',
        );
    }

    public function testCancelOnExpiredPendingStillWorksForCleanup(): void
    {
        // Auch wenn die TTL überschritten ist, soll der Cancel-Link greifen —
        // sonst stehen tote `pending_email*`-Werte im Datensatz, bis der
        // Cleanup-Command läuft. DSGVO-Datensparsamkeit: lieber sofort weg.
        $user = User::factory()->create();
        $plainToken = $this->seedPendingChange($user, 'neu@example.com', Carbon::now()->subDay())['cancel'];

        $response = $this->post(route('email.change.cancel.perform', ['token' => $plainToken]));

        $response->assertOk();
        $this->assertNull($user->fresh()?->pending_email);
    }
}
