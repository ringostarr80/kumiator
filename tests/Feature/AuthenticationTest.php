<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
