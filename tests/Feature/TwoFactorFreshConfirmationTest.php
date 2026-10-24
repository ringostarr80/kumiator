<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\TwoFactorAuthenticationForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfirmsPassword;
use Tests\TestCase;

/**
 * Was die 2FA-Verwaltung anlegt oder zeigt, überdauert die Sitzung: Setup-Key
 * und Wiederherstellungscodes führen zusammen mit dem Passwort an der 2FA
 * vorbei, und eine fremd eingeschaltete 2FA sperrt den Eigentümer aus. Die
 * Bestätigung von vor Stunden, mit der die übrigen Profilbereiche auskommen,
 * reicht deshalb an keinem Ende.
 */
final class TwoFactorFreshConfirmationTest extends TestCase
{
    use ConfirmsPassword;
    use RefreshDatabase;

    #[DataProvider('actions')]
    public function testAnAgedConfirmationNoLongerReachesTheAction(string $action): void
    {
        $this->actingAs(User::factory()->create());
        $this->confirmPassword(600);

        Livewire::test(TwoFactorAuthenticationForm::class)
            ->call($action)
            ->assertForbidden();
    }

    /** Sonst liefe der Klick ohne Dialog in das 403 der Aktion. */
    public function testAnAgedConfirmationReopensTheDialog(): void
    {
        $this->actingAs(User::factory()->create());
        $this->confirmPassword(600);

        Livewire::test(TwoFactorAuthenticationForm::class)
            ->call('startConfirmingPassword', md5('enableTwoFactorAuthentication'))
            ->assertSet('confirmingPassword', true)
            ->assertDispatched('confirming-password');
    }

    #[DataProvider('routes')]
    public function testTheRoutesRejectAnAgedConfirmation(string $method, string $path): void
    {
        $this->actingAs(User::factory()->create())
            ->confirmPassword(600)
            ->json($method, $path)
            ->assertStatus(Response::HTTP_LOCKED);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actions(): array
    {
        return [
            'einschalten' => ['enableTwoFactorAuthentication'],
            'Einrichtung abschließen' => ['confirmTwoFactorAuthentication'],
            'Codes zeigen' => ['showRecoveryCodes'],
            'Codes neu erzeugen' => ['regenerateRecoveryCodes'],
            'ausschalten' => ['disableTwoFactorAuthentication'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function routes(): array
    {
        return [
            'einschalten' => ['POST', '/user/two-factor-authentication'],
            'Einrichtung abschließen' => ['POST', '/user/confirmed-two-factor-authentication'],
            'ausschalten' => ['DELETE', '/user/two-factor-authentication'],
            'QR-Code' => ['GET', '/user/two-factor-qr-code'],
            'Setup-Key' => ['GET', '/user/two-factor-secret-key'],
            'Codes zeigen' => ['GET', '/user/two-factor-recovery-codes'],
            'Codes neu erzeugen' => ['POST', '/user/two-factor-recovery-codes'],
        ];
    }
}
