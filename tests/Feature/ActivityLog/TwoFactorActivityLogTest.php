<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Validiert, dass der `LogTwoFactorActivityListener` 2FA-Events von Fortify
 * korrekt ins Activity-Log überführt — symmetrisch zum bestehenden
 * Authentifizierungs-Trail. Tests dispatchen die Fortify-Events direkt;
 * der Listener-Vertrag ist „Event X → Activity Y".
 */
final class TwoFactorActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testEnablingTwoFactorIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new TwoFactorAuthenticationEnabled($user));

        $this->assertActivityLogged($user, '2fa_enabled', 'app.activity_2fa_enabled');
    }

    public function testConfirmingTwoFactorIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new TwoFactorAuthenticationConfirmed($user));

        $this->assertActivityLogged($user, '2fa_confirmed', 'app.activity_2fa_confirmed');
    }

    public function testDisablingTwoFactorIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new TwoFactorAuthenticationDisabled($user));

        $this->assertActivityLogged($user, '2fa_disabled', 'app.activity_2fa_disabled');
    }

    public function testRegeneratingRecoveryCodesIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new RecoveryCodesGenerated($user));

        $key = 'app.activity_2fa_recovery_codes_regenerated';
        $this->assertActivityLogged($user, '2fa_recovery_codes_regenerated', $key);
    }

    public function testUsingRecoveryCodeIsLoggedWithoutCodeContent(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();
        $secretCode = 'abcd-efgh-ijkl';

        Event::dispatch(new RecoveryCodeReplaced($user, $secretCode));

        $activity = $this->assertActivityLogged($user, '2fa_recovery_code_used', 'app.activity_2fa_recovery_code_used');

        $properties = $activity->properties?->toArray() ?? [];
        $serialized = json_encode($properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            $secretCode,
            $serialized,
            'Der verbrauchte Recovery-Code darf niemals im Klartext im Activity-Log auftauchen.',
        );
    }

    public function testFailedTwoFactorAttemptIsLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        Event::dispatch(new TwoFactorAuthenticationFailed($user));

        $this->assertActivityLogged($user, '2fa_failed', 'app.activity_2fa_failed');
    }

    #[DataProvider('twoFactorTranslationKeyProvider')]
    public function testTranslationKeyForTwoFactorEventExistsInBothLocales(string $key): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->assertNotSame(
                $key,
                Lang::get($key, [], $locale),
                sprintf("Übersetzungs-Schlüssel '%s' fehlt in Locale '%s'.", $key, $locale),
            );
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function twoFactorTranslationKeyProvider(): iterable
    {
        yield 'enabled' => ['app.activity_2fa_enabled'];
        yield 'confirmed' => ['app.activity_2fa_confirmed'];
        yield 'disabled' => ['app.activity_2fa_disabled'];
        yield 'recovery codes regenerated' => ['app.activity_2fa_recovery_codes_regenerated'];
        yield 'recovery code used' => ['app.activity_2fa_recovery_code_used'];
        yield 'failed' => ['app.activity_2fa_failed'];
    }

    private function assertActivityLogged(User $user, string $eventCode, string $descriptionKey): Activity
    {
        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $eventCode)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__($descriptionKey), $activity->description);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->subject_type);
        $this->assertSame($user->getKey(), $activity->subject_id);

        return $activity;
    }
}
