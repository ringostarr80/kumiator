<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebAuthnUserHandleExposureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jetstreams Profilkomponente baut ihren Livewire-State aus `toArray()`.
     * Fehlt der Handle in `$hidden`, steht er im Snapshot jedes gerenderten
     * Profilseiten-HTML — und damit in Browser-Cache, Screenshots und allem,
     * was DOM-Inhalte einsammelt.
     */
    public function testTheProfilePageDoesNotCarryTheHandle(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/user/profile')
            ->assertOk()
            ->assertDontSee($user->getWebAuthnUserHandle());
    }

    /**
     * `/api/user` gibt das Model roh als JSON zurück und prüft keine Ability:
     * Ohne `$hidden` bekäme jedes vom Nutzer ausgestellte Token den Handle,
     * unabhängig davon, wofür es gedacht war.
     */
    public function testTheApiUserEndpointDoesNotCarryTheHandle(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('exposure-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonMissingPath('webauthn_user_handle');
    }
}
