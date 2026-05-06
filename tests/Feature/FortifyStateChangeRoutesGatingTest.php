<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stellt sicher, dass die zustandsändernden Fortify-Endpunkte
 * (`/user/profile-information`, `/user/password`, `/user/two-factor-*`)
 * von unverified bzw. unapproved Usern auch per direktem HTTP-Call nicht
 * mehr erreichbar sind.
 *
 * Über die UI sind diese Endpunkte ohnehin nicht erreichbar (sobald
 * `/user/profile` zu ist), aber ohne Re-Registrierung in `routes/web.php`
 * blieben sie per `curl` direkt aufrufbar — ein unverified User könnte so
 * etwa seinen Namen ändern, sein Passwort wechseln oder 2FA aktivieren.
 */
final class FortifyStateChangeRoutesGatingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('gatedRouteProvider')]
    public function testUnverifiedUserIsBlockedOnStateChangeRoute(string $method, string $path): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->call($method, $path)
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    #[DataProvider('gatedRouteProvider')]
    public function testUnapprovedButVerifiedUserIsBlockedOnStateChangeRoute(string $method, string $path): void
    {
        $user = User::factory()->unapproved()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->call($method, $path)
            ->assertRedirect(route('registration.pending', absolute: false));
    }

    #[DataProvider('gatedRouteProvider')]
    public function testGuestIsBlockedOnStateChangeRoute(string $method, string $path): void
    {
        $this->call($method, $path)
            ->assertRedirect(route('login', absolute: false));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function gatedRouteProvider(): iterable
    {
        // [HTTP-Methode, Pfad]
        yield 'profile information' => ['PUT', '/user/profile-information'];
        yield 'password update' => ['PUT', '/user/password'];
        yield '2fa enable' => ['POST', '/user/two-factor-authentication'];
        yield '2fa confirm' => ['POST', '/user/confirmed-two-factor-authentication'];
        yield '2fa disable' => ['DELETE', '/user/two-factor-authentication'];
        yield '2fa qr code' => ['GET', '/user/two-factor-qr-code'];
        yield '2fa secret key' => ['GET', '/user/two-factor-secret-key'];
        yield '2fa recovery codes index' => ['GET', '/user/two-factor-recovery-codes'];
        yield '2fa recovery codes regenerate' => ['POST', '/user/two-factor-recovery-codes'];
    }
}
