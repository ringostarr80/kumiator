<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\PasskeyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Validiert den Activity-Log-Eintrag bei fehlgeschlagenen Passkey-Anmelde-
 * Versuchen. Symmetrisch zum `login_failed`-Pfad
 * ({@see \App\Listeners\LogAuthenticationActivityListener::handleFailed}):
 * `log_name=auth`, kein Causer, kein Subject, Klartext-Credential-ID
 * niemals in Properties.
 *
 * Tests laufen direkt gegen die statische Domain-Methode
 * {@see \App\Models\PasskeyCredential::recordFailedLoginActivity()}; den
 * Controller-Aufruf in den Catch-Pfaden des
 * {@see \App\Http\Controllers\Auth\PasskeyAuthenticationController} verifiziert
 * {@see \Tests\Feature\PasskeyAuthenticationTest}.
 */
final class PasskeyAuthenticationFailedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testRecordsActivityLogEntryWithCredentialIdHash(): void
    {
        $rawBody = (string) json_encode(['rawId' => 'AAECAwQFBgcICQ', 'id' => 'AAECAwQFBgcICQ']);

        PasskeyCredential::recordFailedLoginActivity('verification_failed', $rawBody);

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_passkey_login_failed'), $activity->description);
        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('verification_failed', $properties['failure_reason'] ?? null);
        $this->assertSame(hash('sha256', 'AAECAwQFBgcICQ'), $properties['credential_id_hash'] ?? null);
    }

    public function testRecordsInternalErrorReason(): void
    {
        PasskeyCredential::recordFailedLoginActivity(
            'internal_error',
            (string) json_encode(['rawId' => 'XYZ']),
        );

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('internal_error', $properties['failure_reason'] ?? null);
    }

    public function testNeverContainsPlaintextCredentialId(): void
    {
        $secretCredentialId = 'super-secret-credential-id';

        PasskeyCredential::recordFailedLoginActivity(
            'verification_failed',
            (string) json_encode(['rawId' => $secretCredentialId]),
        );

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);

        $serialised = (string) json_encode($activity->properties?->toArray() ?? []);
        $this->assertStringNotContainsString(
            $secretCredentialId,
            $serialised,
            'Klartext-Credential-ID darf niemals in den Activity-Log-Properties auftauchen.',
        );
    }

    public function testNonJsonBodyOmitsCredentialIdHash(): void
    {
        PasskeyCredential::recordFailedLoginActivity('verification_failed', 'not-json-at-all');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('verification_failed', $properties['failure_reason'] ?? null);
        $this->assertArrayNotHasKey('credential_id_hash', $properties);
    }

    public function testJsonBodyWithoutCredentialIdOmitsHash(): void
    {
        PasskeyCredential::recordFailedLoginActivity(
            'verification_failed',
            (string) json_encode(['response' => ['something' => 'else']]),
        );

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('credential_id_hash', $properties);
    }

    public function testEmptyBodyOmitsCredentialIdHash(): void
    {
        PasskeyCredential::recordFailedLoginActivity('verification_failed', '');

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'passkey_login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('credential_id_hash', $properties);
    }
}
