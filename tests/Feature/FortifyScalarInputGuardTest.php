<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Array-Input auf den Skalar-Feldern (email/password) erreicht Fortifys
 * Controller, bevor irgendeine Validierung greift, und schlägt dort mit einem
 * TypeError zu HTTP 500 fehl. Der vorgeschaltete Guard muss stattdessen 422
 * liefern — auch auf dem öffentlich erreichbaren Registrierungsweg.
 */
final class FortifyScalarInputGuardTest extends TestCase
{
    use RefreshDatabase;

    private const string REGISTER_URL_PATH = '/register';
    private const string CONFIRM_PASSWORD_URL_PATH = '/user/confirm-password';
    private const string PROFILE_INFORMATION_URL_PATH = '/user/profile-information';

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
}
