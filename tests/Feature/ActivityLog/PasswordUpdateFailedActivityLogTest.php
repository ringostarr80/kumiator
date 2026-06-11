<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Validiert den Activity-Log-Eintrag bei fehlgeschlagener Passwortänderung
 * wegen Mismatch des aktuellen Passworts (`current_password`-Regelverletzung).
 * Symmetrisch zum Erfolgsfall (`password_updated`): `log_name=auth`, Causer
 * und Subject = der authentifizierte User. Reine Passwort-Rule-Fehler ohne
 * `current_password`-Mismatch werden bewusst NICHT geloggt (UX-Fehler ohne
 * Sicherheitssignal).
 */
final class PasswordUpdateFailedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testWrongCurrentPasswordIsLogged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-old-password')]);
        $this->actingAs($user);
        Activity::query()->delete();

        try {
            (new UpdateUserPassword())->update($user, [
                'current_password' => 'wrong-old-password',
                'password' => 'BrandNewStrongPass!123',
                'password_confirmation' => 'BrandNewStrongPass!123',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_update_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_password_update_failed'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->subject_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('current_password_mismatch', $properties['failure_reason'] ?? null);
    }

    public function testWrongCurrentPasswordCombinedWithWeakNewPasswordStillLogsCurrentPasswordMismatch(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-old-password')]);
        $this->actingAs($user);
        Activity::query()->delete();

        try {
            (new UpdateUserPassword())->update($user, [
                'current_password' => 'wrong-old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            // erwartet — sowohl current_password als auch password schlagen fehl.
        }

        $entries = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_update_failed')
            ->get();

        $this->assertCount(1, $entries);
        $properties = $entries->first()?->properties?->toArray() ?? [];
        $this->assertSame('current_password_mismatch', $properties['failure_reason'] ?? null);
    }

    public function testWeakNewPasswordWithoutCurrentPasswordMismatchIsNotLogged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-old-password')]);
        $this->actingAs($user);
        Activity::query()->delete();

        try {
            (new UpdateUserPassword())->update($user, [
                'current_password' => 'correct-old-password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayNotHasKey('current_password', $e->errors());
            $this->assertArrayHasKey('password', $e->errors());
        }

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_update_failed')
                ->count(),
            'Reine Passwort-Rule-Fehler sind UX-Eingabefehler und dürfen das Audit-Log '
            . 'nicht fluten — nur current_password-Mismatch ist forensisch relevant.',
        );
    }

    public function testMissingCurrentPasswordIsNotLogged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-old-password')]);
        $this->actingAs($user);
        Activity::query()->delete();

        try {
            (new UpdateUserPassword())->update($user, [
                'password' => 'BrandNewStrongPass!123',
                'password_confirmation' => 'BrandNewStrongPass!123',
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_update_failed')
                ->count(),
            'Ein fehlendes current_password-Feld (required-Verstoß, z. B. direkter '
            . 'HTTP-Call) ist kein geprüfter Mismatch und darf nicht als '
            . 'current_password_mismatch im Audit-Log landen.',
        );
    }

    public function testSuccessfulPasswordUpdateDoesNotLogFailureEntry(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-old-password')]);
        $this->actingAs($user);
        Activity::query()->delete();

        (new UpdateUserPassword())->update($user, [
            'current_password' => 'correct-old-password',
            'password' => 'BrandNewStrongPass!123',
            'password_confirmation' => 'BrandNewStrongPass!123',
        ]);

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'password_update_failed')
                ->count(),
        );
    }
}
