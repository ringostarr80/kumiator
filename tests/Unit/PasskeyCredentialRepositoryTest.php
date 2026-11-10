<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

final class PasskeyCredentialRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyCredentialRepository $repository;

    public function testFindByUuidOrFailReturnsModelForKnownUuid(): void
    {
        $model = PasskeyCredential::factory()->create();

        $result = $this->repository->findByUuidOrFail($model->uuid);

        $this->assertSame($model->id, $result->id);
    }

    public function testFindByUuidOrFailThrowsForUnknownUuid(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repository->findByUuidOrFail('00000000-0000-0000-0000-000000000000');
    }

    public function testFindByCredentialIdReturnsNullForUnknownId(): void
    {
        $result = $this->repository->findByCredentialId('unknown-id');

        $this->assertNull($result);
    }

    public function testFindByCredentialIdReturnsModelForKnownId(): void
    {
        $passkey = PasskeyCredential::factory()->create();

        $result = $this->repository->findByCredentialId($passkey->credential_id);

        $this->assertNotNull($result);
        $this->assertSame($passkey->id, $result->id);
    }

    /**
     * Base64URL unterscheidet Groß- und Kleinschreibung, die beiden IDs gehören
     * verschiedenen Passkeys. Die ID bringt der Client bei der Registrierung mit.
     * Faltete die Spalte sie zusammen, scheiterte der zweite am Unique-Index, und
     * der Lookup bei der Anmeldung fände womöglich den falschen.
     */
    public function testFindByCredentialIdTellsApartIdsThatDifferOnlyInCase(): void
    {
        $lower = PasskeyCredential::factory()->create(['credential_id' => 'abc-credential']);
        $upper = PasskeyCredential::factory()->create(['credential_id' => 'ABC-credential']);

        $this->assertSame($lower->id, $this->repository->findByCredentialId('abc-credential')?->id);
        $this->assertSame($upper->id, $this->repository->findByCredentialId('ABC-credential')?->id);
    }

    public function testFindAllForUserReturnsOnlyUserCredentials(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        PasskeyCredential::factory()->for($user)->count(2)->create();
        PasskeyCredential::factory()->for($other)->create();

        $result = $this->repository->findAllForUser($user);

        $this->assertCount(2, $result);
    }

    public function testFindAllForUserReturnsNewestFirst(): void
    {
        $user = User::factory()->create();
        $old = PasskeyCredential::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $new = PasskeyCredential::factory()->for($user)->create();

        $result = $this->repository->findAllForUser($user);

        $this->assertCount(2, $result);
        $this->assertSame($new->id, $result->get(0)?->id);
        $this->assertSame($old->id, $result->get(1)?->id);
    }

    public function testSaveNewCredentialPersistsRecord(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();

        $model = $this->repository->saveNewCredential($user, $data, 'My Key');

        $this->assertInstanceOf(PasskeyCredential::class, $model);
        $this->assertSame($user->id, $model->user_id);
        $this->assertSame('My Key', $model->name);
        $this->assertSame($data->credentialId, $model->credential_id);
        $this->assertSame($data->counter, $model->counter);
        $this->assertSame($data->transports, $model->transports);
        $this->assertSame($data->backupEligible, $model->backup_eligible);
        $this->assertSame($data->backupState, $model->backup_state);
        $this->assertSame($data->aaguid, $model->aaguid);
        $this->assertDatabaseHas('passkey_credentials', ['user_id' => $user->id]);
    }

    public function testSaveNewCredentialStoresSerializedJson(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();

        $model = $this->repository->saveNewCredential($user, $data, 'Key');

        $this->assertSame(
            $data->serializedCredentialSource,
            $this->repository->getSerializedCredentialSource($model),
        );
    }

    /**
     * Die WebAuthn-Bibliothek nimmt Credential-IDs bis 1023 Byte an, als Base64URL
     * 1364 Zeichen. Fasste die Spalte weniger, scheiterte die Registrierung solcher
     * Passkeys beim Speichern. SQLite setzt die Länge nicht durch, die Probe gilt
     * MariaDB und PostgreSQL.
     */
    public function testSaveNewCredentialKeepsTheLongestIdTheLibraryAccepts(): void
    {
        $credentialId = Base64UrlSafe::encodeUnpadded(random_bytes(1_023));

        $this->repository->saveNewCredential(
            User::factory()->create(),
            $this->buildNewCredentialData(credentialId: $credentialId),
            'Key',
        );

        $this->assertSame($credentialId, $this->repository->findByCredentialId($credentialId)?->credential_id);
    }

    public function testUpdateAfterAuthenticationUpdatesCounter(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();
        $model = $this->repository->saveNewCredential($user, $data, 'Key');
        $this->assertSame(0, $model->counter);

        $this->repository->updateAfterAuthentication($model, '{"updated":true}', 42, false);

        $model->refresh();
        $this->assertSame(42, $model->counter);
        $this->assertNotNull($model->last_used_at);
    }

    public function testUpdateAfterAuthenticationUpdatesSerializedJson(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();
        $model = $this->repository->saveNewCredential($user, $data, 'Key');

        $updatedJson = '{"counter":99}';
        $this->repository->updateAfterAuthentication($model, $updatedJson, 99, false);

        $model->refresh();
        $this->assertSame($updatedJson, $this->repository->getSerializedCredentialSource($model));
    }

    public function testUpdateAfterAuthenticationSetsLastUsedAtToCurrentTime(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();
        $model = $this->repository->saveNewCredential($user, $data, 'Key');
        $this->assertNull($model->last_used_at);

        $before = now()->floorSeconds();
        $serialized = $this->repository->getSerializedCredentialSource($model);
        $this->repository->updateAfterAuthentication($model, $serialized, 0, false);
        $after = now()->ceilSeconds();

        $model->refresh();
        $this->assertNotNull($model->last_used_at);
        $this->assertTrue(
            $model->last_used_at->between($before, $after),
            "last_used_at ({$model->last_used_at}) is not between {$before} and {$after}",
        );
    }

    public function testUpdateAfterAuthenticationDoesNotChangeBackupEligible(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData(backupEligible: true);
        $model = $this->repository->saveNewCredential($user, $data, 'Key');
        $this->assertTrue($model->backup_eligible);

        // backup_eligible is a registration-time property and must never be altered
        // by a subsequent authentication.
        $serialized = $this->repository->getSerializedCredentialSource($model);
        $this->repository->updateAfterAuthentication($model, $serialized, 1, false);

        $model->refresh();
        $this->assertTrue($model->backup_eligible);
    }

    public function testUpdateAfterAuthenticationUpdatesBackupState(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData(backupState: false);
        $model = $this->repository->saveNewCredential($user, $data, 'Key');
        $this->assertFalse($model->backup_state);

        $serialized = $this->repository->getSerializedCredentialSource($model);
        $this->repository->updateAfterAuthentication($model, $serialized, 0, true);

        $model->refresh();
        $this->assertTrue($model->backup_state);
    }

    public function testGetSerializedCredentialSourceReturnsStoredJson(): void
    {
        $user = User::factory()->create();
        $data = $this->buildNewCredentialData();
        $model = $this->repository->saveNewCredential($user, $data, 'Key');

        $result = $this->repository->getSerializedCredentialSource($model);

        $this->assertSame($data->serializedCredentialSource, $result);
    }

    public function testDeleteRemovesModel(): void
    {
        $model = PasskeyCredential::factory()->create();

        $this->repository->delete($model);

        $this->assertModelMissing($model);
    }

    public function testCountForUserCountsOnlyThePasskeysOfThatUser(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();
        PasskeyCredential::factory()->create();

        $this->assertSame(2, $this->repository->countForUser($user));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PasskeyCredentialRepository();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────────────────

    private function buildNewCredentialData(
        bool $backupState = false,
        bool $backupEligible = false,
        string $credentialId = 'dGVzdC1jcmVkZW50aWFsLWlk',
    ): NewPasskeyCredentialData {
        return new NewPasskeyCredentialData(
            credentialId: $credentialId,
            serializedCredentialSource: '{"type":"public-key","id":"dGVzdA"}',
            counter: 0,
            transports: ['internal'],
            backupEligible: $backupEligible,
            backupState: $backupState,
            aaguid: '00000000-0000-0000-0000-000000000000',
        );
    }
}
