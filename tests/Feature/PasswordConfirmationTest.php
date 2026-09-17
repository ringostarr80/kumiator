<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PasskeyCredential;
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

    /**
     * Ohne nutzbares Passwort und ohne Passkey fielen beide Bedienelemente weg,
     * und übrig blieb ein Text, der ein Passwortfeld verlangt, das die Seite gar
     * nicht mehr ausgibt.
     */
    public function testTheScreenNamesTheWayOutWhenNeitherMethodIsLeft(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $response = $this->actingAs($user)->get(self::CONFIRM_PASSWORD_URL_PATH);

        $response->assertOk();
        $response->assertSee(__('app.password_confirmation_unavailable'));
        $response->assertDontSee(__('app.secure_area'));
    }

    /**
     * Solange ein Passkey bleibt, führt die Seite weiter aus dem geschützten
     * Bereich heraus — aber nicht mehr über das Passwort: Dessen Formular gibt
     * sie hier nicht aus, und ein Text, der es verlangt, ginge ins Leere.
     */
    public function testTheScreenAsksForProofInsteadOfThePasswordWhileAPasskeyRemains(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(self::CONFIRM_PASSWORD_URL_PATH);

        $response->assertSee(__('app.confirm_with_passkey'));
        $response->assertSee(__('app.confirm_password_or_passkey_security'));
        $response->assertDontSee(__('app.secure_area'));
    }

    /**
     * Mit aktivem Passwort-Login und Passkey bietet die Seite beide Wege an; ein
     * Text, der allein das Passwort verlangt, widerspräche dem Knopf darunter.
     */
    public function testTheScreenAsksForProofInsteadOfThePasswordWhenBothWaysAreOffered(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(self::CONFIRM_PASSWORD_URL_PATH);

        $response->assertSee('name="password"', false);
        $response->assertSee(__('app.confirm_with_passkey'));
        $response->assertSee(__('app.confirm_password_or_passkey_security'));
        $response->assertDontSee(__('app.secure_area'));
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
