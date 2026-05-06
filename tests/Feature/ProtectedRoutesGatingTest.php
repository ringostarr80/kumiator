<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sicherstellen, dass die zwei Tore (`verified` + `approved`) korrekt vor
 * der Profilseite und der Pending-Approval-Statusseite gesetzt sind.
 *
 * Hintergrund: Jetstream registriert `/user/profile` standardmässig OHNE
 * `verified`/`approved` (damit ein Tippfehler in der Email noch korrigierbar
 * ist). In diesem Projekt korrigiert das der Admin, deshalb überschreibt
 * `routes/web.php` die Route mit beiden Toren.
 */
final class ProtectedRoutesGatingTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTRATION_PENDING_URL = '/registration-pending';
    private const string USER_PROFILE_URL = '/user/profile';

    public function testGuestIsRedirectedToLoginFromProfile(): void
    {
        $this->get(self::USER_PROFILE_URL)
            ->assertRedirect(route('login', absolute: false));
    }

    public function testUnverifiedUserIsRedirectedToVerificationNoticeFromProfile(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(self::USER_PROFILE_URL)
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function testUnapprovedButVerifiedUserIsRedirectedToRegistrationPendingFromProfile(): void
    {
        $user = User::factory()->unapproved()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(self::USER_PROFILE_URL)
            ->assertRedirect(route('registration.pending', absolute: false));
    }

    public function testApprovedAndVerifiedUserCanReachProfile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(self::USER_PROFILE_URL)
            ->assertOk();
    }

    public function testGuestIsRedirectedToLoginFromRegistrationPending(): void
    {
        $this->get(self::REGISTRATION_PENDING_URL)
            ->assertRedirect(route('login', absolute: false));
    }

    public function testAuthenticatedUnapprovedUserSeesRegistrationPendingPage(): void
    {
        $user = User::factory()->unapproved()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(self::REGISTRATION_PENDING_URL)
            ->assertOk()
            ->assertSeeText(__('app.registration_pending_title'))
            ->assertSeeText(__('app.registration_pending_message'));
    }

    public function testApprovedUserCanAlsoReachRegistrationPendingPage(): void
    {
        // /registration-pending hat bewusst kein `approved`-Gate — der
        // freigeschaltete User wird nicht aktiv geblockt, kann die Seite
        // also direkt aufrufen, sieht aber nur den Hinweistext.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(self::REGISTRATION_PENDING_URL)
            ->assertOk();
    }
}
