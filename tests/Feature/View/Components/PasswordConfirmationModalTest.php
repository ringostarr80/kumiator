<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gerendert wird über eine einbettende Komponente, weil der Dialog sein
 * `wire:model.live` an eine Livewire-Instanz bindet und ohne sie nicht
 * ausgegeben werden kann.
 */
final class PasswordConfirmationModalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ohne nutzbares Passwort und ohne Passkey bleibt im Dialog nur „Abbrechen";
     * Titel und Einleitungstext versprächen sonst einen Nachweis, den dort
     * niemand mehr führen kann.
     */
    public function testTheDialogNamesTheWayOutWhenNeitherMethodIsLeft(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSee(__('app.confirm_identity_title'))
            ->assertDontSee(__('app.confirm_password_title'))
            ->assertSee(__('app.password_confirmation_unavailable'))
            ->assertDontSee(__('app.confirm_password_or_passkey_security'));
    }

    /**
     * Solange ein Passkey bleibt, führt der Dialog weiter zur Bestätigung — aber
     * nicht mehr über das Passwort, und so darf auch der Titel es nicht nennen.
     */
    public function testTheDialogKeepsItsUsualTextWhileAPasskeyRemains(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSee(__('app.confirm_identity_title'))
            ->assertDontSee(__('app.confirm_password_title'))
            ->assertSee(__('app.confirm_password_or_passkey_security'))
            ->assertDontSee(__('app.password_confirmation_unavailable'));
    }

    /**
     * Steht der Passkey-Knopf neben dem Passwortfeld, darf der Text nicht allein
     * das Passwort verlangen — er widerspräche dem Knopf direkt darunter.
     */
    public function testTheDialogAsksForProofInsteadOfThePasswordWhenBothWaysAreOffered(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSee('wire:model="confirmablePassword"', false)
            ->assertSee(__('app.confirm_with_passkey'))
            ->assertSee(__('app.confirm_identity_title'))
            ->assertDontSee(__('app.confirm_password_title'))
            ->assertSee(__('app.confirm_password_or_passkey_security'))
            ->assertDontSee(__('app.confirm_password_security'));
    }

    /** Ist das Passwort der einzige Weg, dürfen Titel und Text es weiter beim Namen nennen. */
    public function testTheDialogStillAsksForThePasswordWhileItIsTheOnlyWay(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSee(__('app.confirm_password_title'))
            ->assertDontSee(__('app.confirm_identity_title'))
            ->assertSee(__('app.confirm_password_security'))
            ->assertDontSee(__('app.confirm_password_or_passkey_security'));
    }

    /**
     * Jedes Formular der Profilseite bettet den Dialog einmal ein, und alle
     * Einbettungen stellen dieselbe Frage an dasselbe Konto. Beantwortet sie
     * jede für sich, wächst die Zahl der Abfragen mit der Zahl der Formulare.
     */
    public function testTheProfilePageAsksOnceWhetherAPasskeyExists(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        $existenceQueries = 0;

        DB::listen(static function (QueryExecuted $query) use (&$existenceQueries): void {
            if (str_contains($query->sql, 'passkey_credentials') && str_contains($query->sql, 'exists')) {
                ++$existenceQueries;
            }
        });

        $this->actingAs($user)->get('/user/profile')->assertOk();

        $this->assertSame(1, $existenceQueries);
    }
}
