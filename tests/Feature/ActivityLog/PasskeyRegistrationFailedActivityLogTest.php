<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Validiert den Activity-Log-Eintrag bei fehlgeschlagenen Passkey-
 * Registrierungs-Versuchen. Symmetrisch zum erfolgreichen Lifecycle-Pfad
 * (`passkey_registered`): `log_name=passkey`, Causer = der authentifizierte
 * User, kein Subject (es entsteht keine Credential).
 *
 * Tests laufen direkt gegen die statische Domain-Methode
 * {@see \App\Models\PasskeyCredential::recordFailedRegistrationActivity()};
 * den Controller-Aufruf in den Catch-Pfaden des
 * {@see \App\Http\Controllers\Auth\PasskeyRegistrationController} verifiziert
 * {@see \Tests\Feature\Http\Controllers\Auth\PasskeyRegistrationTest} (falls
 * vorhanden).
 */
final class PasskeyRegistrationFailedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testRecordsActivityLogEntryWithVerificationFailedReason(): void
    {
        $user = User::factory()->create();

        PasskeyCredential::recordFailedRegistrationActivity($user, 'verification_failed');

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_registration_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_passkey_registration_failed'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('verification_failed', $properties['failure_reason'] ?? null);
    }

    public function testRecordsInternalErrorReason(): void
    {
        $user = User::factory()->create();

        PasskeyCredential::recordFailedRegistrationActivity($user, 'internal_error');

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_registration_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('internal_error', $properties['failure_reason'] ?? null);
    }

    public function testNeverContainsCredentialIdHash(): void
    {
        $user = User::factory()->create();

        PasskeyCredential::recordFailedRegistrationActivity($user, 'verification_failed');

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_registration_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey(
            'credential_id_hash',
            $properties,
            'Bei der Registrierung erzeugt jeder Authenticator eine frische Credential-ID — '
            . 'ein Hash brächte keinen forensischen Mehrwert und steht daher bewusst nicht in den Properties.',
        );
    }

    public function testTranslationKeyExistsInBothLocales(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->assertNotSame(
                'app.activity_passkey_registration_failed',
                Lang::get('app.activity_passkey_registration_failed', [], $locale),
                sprintf("Übersetzungs-Schlüssel fehlt in Locale '%s'.", $locale),
            );
        }
    }
}
