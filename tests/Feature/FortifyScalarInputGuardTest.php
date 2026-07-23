<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Array-Input auf den Skalar-Feldern (email/password/current_password) erreicht
 * Fortifys Controller bzw. die `current_password:web`-Regel, bevor eine
 * String-Grenze greift, und schlägt dort mit einem TypeError zu HTTP 500 fehl.
 * Der vorgeschaltete Guard bzw. `bail` muss stattdessen 422 liefern — auch auf
 * dem öffentlich erreichbaren Registrierungsweg.
 */
final class FortifyScalarInputGuardTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_URL_PATH = '/register';
    private const string CONFIRM_PASSWORD_URL_PATH = '/user/confirm-password';
    private const string PROFILE_INFORMATION_URL_PATH = '/user/profile-information';
    private const string PASSWORD_URL_PATH = '/user/password';

    public function testRegisterRejectsArrayEmailInsteadOfCrashing(): void
    {
        $response = $this->postJson(self::REGISTER_URL_PATH, ['email' => ['case@example.com']]);

        $response->assertStatus(422);
    }

    public function testConfirmPasswordRejectsArrayPasswordInsteadOfCrashing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(self::CONFIRM_PASSWORD_URL_PATH, ['password' => ['password']]);

        $response->assertStatus(422);
    }

    public function testProfileInformationRejectsArrayEmailInsteadOfCrashing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->putJson(self::PROFILE_INFORMATION_URL_PATH, ['email' => ['case@example.com']]);

        $response->assertStatus(422);
    }

    public function testPasswordUpdateRejectsArrayCurrentPasswordInsteadOfCrashing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(self::PASSWORD_URL_PATH, [
            'current_password' => ['password'],
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
    }

    public function testProfileInformationRejectsArrayCurrentPasswordInsteadOfCrashing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->putJson(self::PROFILE_INFORMATION_URL_PATH, [
            'name' => $user->name,
            'email' => 'changed@example.com',
            'current_password' => ['password'],
        ]);

        $response->assertStatus(422);
    }
}
