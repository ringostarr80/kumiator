<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->where('event', 'passkey_registered')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_passkey_registered'), $activity->description);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $this->assertArrayHasKey('attributes', $changes);
        $this->assertIsArray($changes['attributes']);
        // Belegt, dass der Diff überhaupt noch getragen wird — sonst wäre die
        // Namens-Assertion unten auch bei komplett fehlendem Logging grün.
        $this->assertArrayHasKey('aaguid', $changes['attributes']);
        $this->assertArrayNotHasKey('name', $changes['attributes']);
    }

    public function testRenamingPasskeyCredentialCreatesActivityLogEntry(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'Alt']);
        $credential->updateOrFail(['name' => 'Neu']);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('event', 'passkey_renamed')
            ->latest('id')
            ->first();

        // Der Eintrag entsteht weiterhin: Spatie entscheidet über
        // `dontLogEmptyChanges()` bereits beim Bauen des Eintrags, also bevor der
        // Name aus dem Diff fällt. Die Umbenennung bleibt damit nachweisbar —
        // nur nicht mehr, auf welchen Namen.
        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_passkey_renamed'), $activity->description);
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $attributes = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];
        $this->assertIsArray($attributes);
        $this->assertIsArray($old);
        $this->assertArrayNotHasKey('name', $attributes);
        $this->assertArrayNotHasKey('name', $old);
    }

    /**
     * Der Credential-Name ist ein freies, vom Nutzer gefülltes Textfeld — in der
     * Praxis steht dort regelmäßig ein Personenbezug („iPhone von Erika").
     * Anders als bei den `user`-Einträgen (Subject = User) erreicht ihn der
     * DSGVO-Purge des Hard-Deletes nicht: Passkey-Einträge tragen Subject
     * `passkey`, und nach dem Löschen der Credential ist die Zeile über keine
     * Relation mehr einem User zuzuordnen. Der Name darf deshalb gar nicht erst
     * persistiert werden — geprüft über den kompletten Lifecycle und den
     * gesamten serialisierten Eintrag, nicht nur über den Diff.
     */
    public function testActivityLogNeverContainsUserChosenCredentialName(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'iPhone von Erika']);
        $credential->updateOrFail(['name' => 'MacBook von Erika']);
        $credential->deleteOrFail();

        $entries = Activity::query()->where('log_name', 'passkey')->get();

        $this->assertCount(3, $entries, 'Erwartet: registered, renamed, removed.');

        foreach ($entries as $entry) {
            $serialised = json_encode($entry->toArray(), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(
                'Erika',
                $serialised,
                'Der vom Nutzer vergebene Passkey-Name darf in keinem Feld des '
                . 'Activity-Eintrags landen — er überlebt sonst jede Konto-Löschung.',
            );
        }
    }

    public function testDeletingPasskeyCredentialCreatesActivityLogEntry(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'Bye']);
        $subjectId = $credential->getKey();
        $credential->deleteOrFail();

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('subject_id', $subjectId)
            ->where('event', 'passkey_removed')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_passkey_removed'), $activity->description);
    }

    public function testActivityLogNeverContainsSecretFields(): void
    {
        $credential = PasskeyCredential::factory()->create(['name' => 'TestKey']);

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('subject_id', $credential->getKey())
            ->where('event', 'passkey_registered')
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
     * Schützt die Trennung zwischen automatischem Trait-Logging und der
     * dedizierten Login-Aktivität: Ein `updateOrFail` mit ausschließlich
     * Secret-Feldern + `last_used_at` darf KEINEN Trait-Eintrag erzeugen
     * (`last_used_at` ist bewusst aus `logOnly` heraus), und ohne den
     * expliziten `recordSuccessfulLoginActivity()`-Aufruf entsteht auch
     * sonst nichts. So kann nichts mehr versehentlich „mitgeschrieben"
     * werden, was den Login-Trail aufweicht.
     */
    public function testUpdatingSecretAndLastUsedFieldsProducesNoTraitLogEntry(): void
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

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'passkey')
                ->where('subject_id', $credential->getKey())
                ->count(),
            'Login-typische Felder dürfen den `LogsActivity`-Trait nicht mehr triggern '
            . '— der dedizierte Eintrag entsteht ausschließlich über recordSuccessfulLoginActivity().',
        );
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
            ->where('event', 'passkey_renamed')
            ->where('subject_id', $credential->getKey())
            ->first();

        $this->assertNull(
            $activity,
            'Ein Update, das nur Secret-Felder ändert, darf keinen Activity-Log-Eintrag erzeugen.',
        );
    }

    /**
     * Schreibt `recordSuccessfulLoginActivity()` einen Eintrag mit dem
     * fachlichen Event-Code `passkey_login_succeeded`, übersetzter
     * Description und dem Owner als Causer? Diese Methode ist die einzige
     * legitime Quelle des Login-Logs (siehe `PasskeyAuthenticationService`).
     */
    public function testRecordSuccessfulLoginActivityWritesDedicatedEntry(): void
    {
        $owner = User::factory()->create();
        $credential = PasskeyCredential::factory()->for($owner)->create(['name' => 'LoginKey']);
        Activity::query()->delete();

        $credential->recordSuccessfulLoginActivity();

        $activity = Activity::query()
            ->where('log_name', 'passkey')
            ->where('subject_id', $credential->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('passkey_login_succeeded', $activity->event);
        $this->assertSame(__('app.activity_passkey_login_succeeded'), $activity->description);
        $this->assertSame($owner->getMorphClass(), $activity->causer_type);
        $this->assertSame($owner->getKey(), $activity->causer_id);
    }

    /**
     * Wenn die Credential keinen zugeordneten Owner mehr hat (z. B. weil die
     * Belongs-To-Relation `null` liefert), schreibt
     * `recordSuccessfulLoginActivity()` keinen halbvollständigen Eintrag.
     * Die Relation wird hier lokal mit einer leeren Collection geprimed,
     * damit `$this->user` deterministisch `null` zurückgibt — ohne den
     * Foreign-Key der DB anzufassen.
     */
    public function testRecordSuccessfulLoginActivityNoopsWhenOwnerMissing(): void
    {
        $credential = PasskeyCredential::factory()->create();
        $credential->setRelation('user', null);
        Activity::query()->delete();

        $credential->recordSuccessfulLoginActivity();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'passkey')
                ->where('event', 'passkey_login_succeeded')
                ->count(),
        );
    }
}
