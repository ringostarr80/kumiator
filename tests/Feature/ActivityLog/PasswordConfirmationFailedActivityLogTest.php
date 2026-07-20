<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Validiert den Activity-Log-Eintrag bei fehlgeschlagener Passwort-
 * Bestätigung vor 2FA-Endpoints. Beide vendor-seitigen Re-Auth-Pfade —
 * `POST /user/confirm-password` (Fortify-Controller) und Jetstreams
 * `ConfirmsPasswords::confirmPassword()` (Livewire) — laufen durch
 * `Laravel\Fortify\Actions\ConfirmPassword`. Der zentrale Hook in
 * `FortifyServiceProvider::boot()` fängt beide an einer Stelle ab und
 * schreibt `log_name=auth`, `event=password_confirmation_failed`,
 * `causer=user`. Erfolgreiche Bestätigungen schreiben keinen Eintrag.
 */
final class PasswordConfirmationFailedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const string CONFIRM_PASSWORD_URL_PATH = '/user/confirm-password';

    public function testWebFormPathLogsOnWrongPassword(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL_PATH, [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_confirmation_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_confirmation_failed'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);
    }

    public function testWebFormPathDoesNotLogOnCorrectPassword(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL_PATH, [
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_confirmation_failed')
                ->count(),
        );
    }

    public function testWebFormPathDoesNotLogWhenNoPasswordWasSubmitted(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        // Der Fortify-Controller reicht `$request->input('password')` ungeprüft
        // durch: Fehlt das Feld, kommt `null` an, ohne dass je ein Hash
        // verglichen wurde. Ein Mismatch-Audit wäre hier ein Falschsignal.
        $response = $this->actingAs($user)->post(self::CONFIRM_PASSWORD_URL_PATH, []);

        $response->assertSessionHasErrors();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_confirmation_failed')
                ->count(),
        );
    }

    public function testLivewirePathLogsOnWrongPassword(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(TwoFactorAuthenticationForm::class)
            ->set('confirmablePassword', 'wrong-password')
            ->call('confirmPassword')
            ->assertHasErrors('confirmable_password');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_confirmation_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_confirmation_failed'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
    }

    public function testLivewirePathDoesNotLogOnCorrectPassword(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Activity::query()->delete();

        Livewire::test(TwoFactorAuthenticationForm::class)
            ->set('confirmablePassword', 'password')
            ->call('confirmPassword')
            ->assertHasNoErrors();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_confirmation_failed')
                ->count(),
        );
    }
}
