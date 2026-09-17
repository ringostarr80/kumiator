<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Hält die Anmeldung zu, sobald ein Konto den Passwort-Login abgeschaltet hat.
 */
final class PasswordLoginDisabledTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGIN_URL_PATH = '/login';
    private const string PASSWORD = 'password';

    public function testCorrectCredentialsNoLongerAuthenticate(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertGuest();
    }

    public function testCorrectCredentialsStillAuthenticateWithoutTheSwitch(): void
    {
        $user = User::factory()->create();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertAuthenticated();
    }

    /**
     * Der Eintrag ist ein Warnsignal, kein Rauschen: Wer hier auftaucht, kennt
     * ein gültiges Passwort zu einem Konto, das sich damit nicht anmelden kann.
     */
    public function testRejectedLoginIsAuditedWithItsOwnEvent(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'login_password_disabled')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getKey(), $activity->subject_id);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('web', $properties['guard'] ?? null);
        $this->assertArrayHasKey('email_hash', $properties);
    }

    public function testRejectedLoginDoesNotAlsoCountAsAGenericFailure(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(0, Activity::query()->where('event', 'login_failed')->count());
    }

    /**
     * Ein falsches Passwort darf weiterhin als generischer Fehlversuch zählen —
     * sonst verschluckte der Marker den Brute-Force-Trail solcher Konten.
     */
    public function testWrongCredentialsStillCountAsAGenericFailure(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        Activity::query()->delete();

        $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(1, Activity::query()->where('event', 'login_failed')->count());
        $this->assertSame(0, Activity::query()->where('event', 'login_password_disabled')->count());
    }

    /**
     * Fällt das Log aus, muss der Nutzer dieselbe reguläre Fehlermeldung sehen —
     * ein 500er machte die Verfügbarkeit des Audit-Logs nach außen sichtbar und
     * verriete nebenbei, dass es dieses Konto gibt.
     */
    public function testTheRejectionSurvivesAFailingAuditWrite(): void
    {
        Exceptions::fake();

        $user = User::factory()->create(['password_login_disabled_at' => now()]);

        Schema::drop('activity_log');

        $response = $this->post(self::LOGIN_URL_PATH, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertGuest();
        $response->assertRedirect();
        $response->assertSessionHasErrors();
        Exceptions::assertReported(QueryException::class);
    }
}
