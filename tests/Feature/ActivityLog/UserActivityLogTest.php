<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class UserActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testUpdatingUserNameCreatesActivityLogEntry(): void
    {
        $user = User::factory()->create(['name' => 'Alt']);
        $user->updateOrFail(['name' => 'Neu']);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $this->assertArrayHasKey('attributes', $changes);
        $this->assertIsArray($changes['attributes']);
        $this->assertSame('Neu', $changes['attributes']['name'] ?? null);
        $this->assertArrayHasKey('old', $changes);
        $this->assertIsArray($changes['old']);
        $this->assertSame('Alt', $changes['old']['name'] ?? null);
    }

    public function testPasswordChangeIsNotLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $user->forceFill(['password' => bcrypt('neuesgeheimnis')])->save();

        $activity = Activity::query()->where('log_name', 'user')->latest('id')->first();

        $this->assertNull($activity, 'Ein reines Passwort-Update darf keinen Activity-Log-Eintrag erzeugen.');
    }

    public function testTwoFactorSecretChangeIsNotLogged(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $user->forceFill([
            'two_factor_secret' => 'geheim',
            'two_factor_recovery_codes' => 'rec',
        ])->save();

        $activity = Activity::query()->where('log_name', 'user')->latest('id')->first();

        $this->assertNull($activity, 'Änderungen an 2FA-Secret oder Recovery-Codes dürfen nicht geloggt werden.');
    }

    public function testActivityLogNeverContainsSecretFields(): void
    {
        $user = User::factory()->create();
        $user->updateOrFail(['name' => 'Geänderter Name']);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $changes = $activity->attribute_changes?->toArray() ?? [];

        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
        $this->assertIsArray($attributes);
        $this->assertIsArray($old);

        $forbiddenKeys = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

        foreach ($forbiddenKeys as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $attributes);
            $this->assertArrayNotHasKey($forbidden, $old);
        }
    }
}
