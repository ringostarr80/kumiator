<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sicherstellen, dass `/user/api-tokens` hinter beiden Toren (`verified` +
 * `approved`) liegt.
 *
 * Jetstream registriert die Route (Feature `api`) standardmässig nur hinter
 * `verified` — ohne `approved`. Ein verifizierter, aber noch nicht
 * freigeschalteter User könnte die Seite sonst aufrufen und dort
 * Sanctum-Tokens anlegen.
 */
final class ApiTokenRouteGatingTest extends TestCase
{
    use RefreshDatabase;

    private const string API_TOKENS_URL = '/user/api-tokens';

    public function testUnapprovedButVerifiedUserIsRedirectedToRegistrationPending(): void
    {
        $user = User::factory()->unapproved()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(self::API_TOKENS_URL)
            ->assertRedirect(route('registration.pending', absolute: false));
    }
}
