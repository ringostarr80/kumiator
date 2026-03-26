<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const string CONFIRM_PASSWORD_URL_PATH = '/user/confirm-password';

    public function testConfirmPasswordScreenCanBeRendered(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->get(self::CONFIRM_PASSWORD_URL_PATH);

        $response->assertStatus(200);
    }

    public function testPasswordCanBeConfirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL_PATH, [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function testPasswordIsNotConfirmedWithInvalidPassword(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL_PATH, [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
