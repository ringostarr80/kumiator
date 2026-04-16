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

        $forbiddenKeys = [
            'credential_id',
            'credential_public_key',
            'counter',
            'transports',
            'backup_eligible',
            'backup_state',
        ];

        foreach ($forbiddenKeys as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $attributes,
                "Das Feld '{$forbidden}' darf niemals im Passkey-Activity-Log auftauchen.",
            );
        }
    }
}
