<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Livewire\Admin\ActivityLogTable;
use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Verifiziert, dass still abgelehnte Autorisierungs-Versuche (HTTP 403 via
 * `Gate::authorize` / `$this->authorize`) als Activity-Log-Einträge im
 * Channel `security` mit Event-Code `authorization_denied` festgehalten
 * werden — eine Symmetrie zu den anderen `*_failed`-Pfaden (Login, Passkey-
 * Registration, E-Mail-Verifikation).
 *
 * Test-Strategie: pro Call-Site ein Test, der eine HTTP-403-Antwort erwartet
 * UND einen passenden Log-Eintrag verifiziert. Negativ-Tests stellen sicher,
 * dass autorisierte Calls **keinen** Eintrag erzeugen.
 */
final class AuthorizationDeniedActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function testRecorderWritesActivityWithExpectedShape(): void
    {
        $user = User::factory()->create();
        $target = PasskeyCredential::factory()->for($user)->create();

        PasskeyCredential::recordAuthorizationDeniedActivity($user, 'update', $target);

        $activity = $this->latestAuthorizationDenied();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_authorization_denied'), $activity->description);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('update', $properties['ability'] ?? null);
        $this->assertSame('passkey_credential', $properties['target_type'] ?? null);
        $this->assertSame(hash('sha256', $target->id), $properties['target_id_hash'] ?? null);
    }

    public function testRecorderAcceptsDeleteAbility(): void
    {
        $user = User::factory()->create();
        $target = PasskeyCredential::factory()->for($user)->create();

        PasskeyCredential::recordAuthorizationDeniedActivity($user, 'delete', $target);

        $activity = $this->latestAuthorizationDenied();

        $this->assertNotNull($activity);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('delete', $properties['ability'] ?? null);
    }

    public function testPasskeyManagerStartRenamingByNonOwnerIsLogged(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->assertForbidden();

        $this->assertAuthorizationDeniedLogged($other, 'update', $passkey);
    }

    public function testPasskeyManagerRenameByNonOwnerIsLogged(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create(['name' => 'Owner-Name']);

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->set('editingPasskeyId', $passkey->id)
            ->set('editingPasskeyName', 'Hijacked')
            ->call('renamePasskey')
            ->assertForbidden();

        $this->assertAuthorizationDeniedLogged($other, 'update', $passkey);
    }

    public function testPasskeyManagerDeleteByNonOwnerIsLogged(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id)
            ->assertForbidden();

        $this->assertAuthorizationDeniedLogged($other, 'delete', $passkey);
    }

    public function testPasskeyRegistrationControllerDestroyByNonOwnerIsLogged(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        $this->actingAs($other)
            ->delete('/user/passkeys/' . $passkey->id)
            ->assertForbidden();

        $this->assertAuthorizationDeniedLogged($other, 'delete', $passkey);
    }

    public function testActivityLogTableMountWithoutPermissionIsLogged(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertForbidden();

        $activity = $this->latestAuthorizationDenied();

        $this->assertNotNull($activity);
        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame($user->getMorphClass(), $activity->causer_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame('activity-log.view', $properties['ability'] ?? null);
        $this->assertArrayHasKey('target_type', $properties);
        $this->assertNull($properties['target_type']);
        $this->assertArrayHasKey('target_id_hash', $properties);
        $this->assertNull($properties['target_id_hash']);
    }

    public function testPasskeyOwnerOperationsDoNotLogAuthorizationDenied(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create(['name' => 'Alt']);

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('startRenaming', $passkey->id)
            ->set('editingPasskeyName', 'Neu')
            ->call('renamePasskey');

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Autorisierte Owner-Operationen dürfen keinen authorization_denied-Eintrag erzeugen.',
        );
    }

    public function testActivityLogTableMountWithPermissionDoesNotLog(): void
    {
        Permission::findOrCreate('activity-log.view');

        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

        Livewire::actingAs($user)
            ->test(ActivityLogTable::class) // @phpstan-ignore argument.templateType
            ->assertOk();

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Erlaubter Zugriff auf das Activity-Log-UI darf keinen authorization_denied-Eintrag erzeugen.',
        );
    }

    private function assertAuthorizationDeniedLogged(User $causer, string $ability, PasskeyCredential $target): void
    {
        $activity = $this->latestAuthorizationDenied();

        $this->assertNotNull(
            $activity,
            sprintf("Kein authorization_denied-Eintrag für ability='%s' gefunden.", $ability),
        );
        $this->assertSame($causer->getKey(), $activity->causer_id);
        $this->assertSame($causer->getMorphClass(), $activity->causer_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame($ability, $properties['ability'] ?? null);
        $this->assertSame('passkey_credential', $properties['target_type'] ?? null);
        $this->assertSame(hash('sha256', $target->id), $properties['target_id_hash'] ?? null);
    }

    private function latestAuthorizationDenied(): ?Activity
    {
        return Activity::query()
            ->where('log_name', 'security')
            ->where('event', 'authorization_denied')
            ->latest('id')
            ->first();
    }
}
