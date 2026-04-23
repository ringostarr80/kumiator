<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\PasskeyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class PasskeyCredentialActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const array SECRET_FIELDS = [
        'credential_id',
        'credential_public_key',
        'counter',
        'transports',
        'backup_eligible',
        'backup_state',
    ];

    public function testCreatingPasskeyCredentialCreatesActivityLogEntry(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'iPhone']);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('subject_type', $credential->getMorphClass())
            ->where('subject_id', $credential->getKey())
            ->where('event', 'created')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $this->assertArrayHasKey('attributes', $changes);
        $this->assertIsArray($changes['attributes']);
        $this->assertSame('iPhone', $changes['attributes']['name'] ?? null);
    }

    public function testRenamingPasskeyCredentialCreatesActivityLogEntry(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'Alt']);
        $credential->updateOrFail(['name' => 'Neu']);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $this->assertIsArray($changes['attributes'] ?? null);
        $this->assertSame('Neu', $changes['attributes']['name'] ?? null);
        $this->assertIsArray($changes['old'] ?? null);
        $this->assertSame('Alt', $changes['old']['name'] ?? null);
    }

    public function testActivityLogNeverContainsSecretFields(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'TestKey']);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('subject_id', $credential->getKey())
            ->where('event', 'created')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $attributes = $changes['attributes'] ?? [];
        $this->assertIsArray($attributes);

        foreach (self::SECRET_FIELDS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $attributes,
                "Das Feld '{$forbidden}' darf niemals im Passkey-Activity-Log auftauchen.",
            );
        }
    }

    /**
     * Regressionsschutz für die reale Call-Site `PasskeyCredentialRepository::updateAfterAuthentication()`:
     * Ein erfolgreicher Passkey-Login schreibt gleichzeitig Secret-Felder (`credential_public_key`,
     * `counter`, `backup_state`) und ein Meta-Feld (`last_used_at`) in die DB. Der Activity-Log
     * darf nur das Meta-Feld enthalten — weder in `attributes` noch in `old` dürfen Secrets
     * auftauchen, auch nicht in ihren Vorgängerwerten.
     */
    public function testUpdateWithMixedFieldsOnlyLogsAllowlistedAttributes(): void
    {
        $credential = PasskeyCredential::factory()->create([
            'name' => 'MixedUpdate',
            'counter' => 0,
            'backup_state' => false,
        ]);
        Activity::query()->delete();

        $credential->updateOrFail([
            'credential_public_key' => 'super-secret-serialized-source',
            'counter' => 42,
            'backup_state' => true,
            'last_used_at' => now(),
        ]);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'updated')
            ->where('subject_id', $credential->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
        $this->assertIsArray($attributes);
        $this->assertIsArray($old);

        $this->assertArrayHasKey('last_used_at', $attributes);

        foreach (self::SECRET_FIELDS as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $attributes,
                "Das Feld '{$forbidden}' darf weder in 'attributes' noch in 'old' geloggt werden.",
            );
            $this->assertArrayNotHasKey(
                $forbidden,
                $old,
                "Das Feld '{$forbidden}' darf weder in 'attributes' noch in 'old' geloggt werden.",
            );
        }
    }

    /**
     * Ein Update, das ausschließlich Secret-Felder anfasst, darf gar keinen Log-Eintrag erzeugen
     * (`dontLogEmptyChanges()`). Andernfalls würde jeder Passkey-Login einen leeren Activity-
     * Eintrag produzieren — Rauschen ohne fachlichen Wert.
     */
    public function testUpdateWithOnlySecretFieldsProducesNoLogEntry(): void
    {
        $credential = PasskeyCredential::factory()->create([
            'name' => 'OnlySecretUpdate',
            'counter' => 0,
            'backup_state' => false,
        ]);
        Activity::query()->delete();

        $credential->updateOrFail([
            'credential_public_key' => 'super-secret-serialized-source',
            'counter' => 99,
            'backup_state' => true,
        ]);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'updated')
            ->where('subject_id', $credential->getKey())
            ->first();

        $this->assertNull(
            $activity,
            'Ein Update, das nur Secret-Felder ändert, darf keinen Activity-Log-Eintrag erzeugen.',
        );
    }
}
