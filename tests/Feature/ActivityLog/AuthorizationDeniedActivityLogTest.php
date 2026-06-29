<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Livewire\Admin\ActivityLogTable;
use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\Audit\AuthorizationAuditor;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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

    public function testDeniedResponseResultIsLogged(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        app(AuthorizationAuditor::class)->record($user, 'delete', Response::deny(), [$passkey]);

        $this->assertAuthorizationDeniedLogged($user, 'delete', $passkey);
    }

    public function testGrantedResponseResultIsNotLogged(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        app(AuthorizationAuditor::class)->record($user, 'update', Response::allow(), [$passkey]);

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Ein erlaubter Zugriff (Response::allow) darf keinen authorization_denied-Eintrag erzeugen.',
        );
    }

    public function testGateDenialWithoutAuditableSubjectIsNotLogged(): void
    {
        $user = User::factory()->create();

        // Wie der reine Sichtbarkeits-`@can('activity-log.view')` im Navigationsmenü:
        // subjektlos, kein Marker-Subjekt — darf den Hook NICHT auslösen, sonst
        // entstünde pro Seitenaufruf jedes Nicht-Admins ein Eintrag.
        $this->assertTrue(Gate::forUser($user)->denies('activity-log.view'));

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Ein subjektloser Gate-Check darf über den globalen Hook nichts loggen.',
        );
    }

    public function testGateDenialOnNonAuditableSubjectIsNotLogged(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // `User` ist morph-gemappt, aber kein AuthorizationAuditable — die
        // Ablehnung darf nicht auditiert werden (Marker-Opt-in ist Pflicht).
        $this->assertTrue(Gate::forUser($user)->denies('update', $other));

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Ohne Marker-Tag am Subjekt darf der Hook nichts loggen.',
        );
    }

    public function testSubjectlessDenialIsLoggedWithNullTarget(): void
    {
        $user = User::factory()->create();

        app(AuthorizationAuditor::class)->recordSubjectlessDenial($user, 'activity-log.view');

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

    public function testSubjectlessDenialWithoutUserCauserIsNotLogged(): void
    {
        app(AuthorizationAuditor::class)->recordSubjectlessDenial(null, 'activity-log.view');

        $this->assertNull(
            $this->latestAuthorizationDenied(),
            'Ohne User-Causer darf recordSubjectlessDenial nichts loggen.',
        );
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
        $this->assertSame(__('app.activity_authorization_denied'), $activity->description);
        $this->assertSame($causer->getKey(), $activity->causer_id);
        $this->assertSame($causer->getMorphClass(), $activity->causer_type);
        $this->assertNull($activity->subject_id);
        $this->assertNull($activity->subject_type);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertSame($ability, $properties['ability'] ?? null);
        $this->assertSame($target->getMorphClass(), $properties['target_type'] ?? null);
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
