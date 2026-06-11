<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGIN_URL_PATH = '/login';

    public function testLoginScreenCanBeRendered(): void
    {
        $response = $this->get(self::LOGIN_URL_PATH);

        $response->assertStatus(200);
    }

    public function testUsersCanAuthenticateUsingTheLoginScreen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function testUsersCanNotAuthenticateWithInvalidPassword(): void
    {
        $user = User::factory()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function testUnknownEmailLoginRunsDummyHashAgainstTimingEnumeration(): void
    {
        // Die Antwortzeit selbst ist hier nicht messbar (BCRYPT_ROUNDS=4 in
        // der Test-Umgebung, ~1 ms gegen Request-Rauschen) — der Spy pinnt
        // stattdessen die Invariante, dass auch der Unbekannt-Pfad genau
        // einen KDF-Lauf ausführt. Bewusste Ausnahme von der
        // Mocks-vermeiden-Regel.
        $hash = Hash::spy();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => 'unbekannt@example.com',
            'password' => 'irrelevant-password',
        ]);

        $this->assertGuest();
        $hash->shouldHaveReceived('make')->once();
    }

    public function testUnapprovedUsersCanNotAuthenticate(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }
}
