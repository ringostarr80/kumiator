<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyCredentialRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyCredentialRepository $repository;

    public function testFindByIdOrFailReturnsModelForKnownId(): void
    {
        $model = PasskeyCredential::factory()->create();

        $result = $this->repository->findByIdOrFail($model->id);

        $this->assertSame($model->id, $result->id);
    }

    public function testFindByIdOrFailThrowsForUnknownId(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->repository->findByIdOrFail('00000000-0000-0000-0000-000000000000');
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
        $credentialRecord = $this->buildPublicKeyCredentialSource();

        $model = $this->repository->saveNewCredential($user, $credentialRecord, 'My Key');

        $this->assertInstanceOf(PasskeyCredential::class, $model);
        $this->assertSame($user->id, $model->user_id);
        $this->assertSame('My Key', $model->name);
        $this->assertDatabaseHas('passkey_credentials', ['user_id' => $user->id]);
    }

    public function testUpdateAfterAuthenticationUpdatesCounter(): void
    {
        $user = User::factory()->create();
        $credentialRecord = $this->buildPublicKeyCredentialSource();

        $model = $this->repository->saveNewCredential($user, $credentialRecord, 'Key');
        $this->assertSame(0, $model->counter);

        $credentialRecord->counter = 42;
        $this->repository->updateAfterAuthentication($model, $credentialRecord);

        $model->refresh();
        $this->assertSame(42, $model->counter);
        $this->assertNotNull($model->last_used_at);
    }

    public function testUpdateAfterAuthenticationPersistsCounterInSerializedJson(): void
    {
        $user = User::factory()->create();
        $credentialRecord = $this->buildPublicKeyCredentialSource();
        $model = $this->repository->saveNewCredential($user, $credentialRecord, 'Key');

        $credentialRecord->counter = 99;
        $this->repository->updateAfterAuthentication($model, $credentialRecord);

        // The counter must also be updated in the serialised credential_public_key blob,
        // not only in the dedicated counter column. The webauthn-lib reads the counter
        // from the deserialised PublicKeyCredentialSource on every subsequent login, so
        // a stale blob would cause the non-monotonic-counter check to compare against the
        // wrong baseline and wrongly accept cloned credentials.
        $model->refresh();
        $deserialised = $this->repository->getPublicKeyCredentialSource($model);
        $this->assertSame(99, $deserialised->counter);
    }

    public function testUpdateAfterAuthenticationSetsLastUsedAtToCurrentTime(): void
    {
        $user = User::factory()->create();
        $model = $this->repository->saveNewCredential($user, $this->buildPublicKeyCredentialSource(), 'Key');
        $this->assertNull($model->last_used_at);

        $before = now()->floorSeconds();
        $credentialRecord = $this->repository->getPublicKeyCredentialSource($model);
        $this->repository->updateAfterAuthentication($model, $credentialRecord);
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
        $credentialRecord = $this->buildPublicKeyCredentialSource(backupEligible: true);
        $model = $this->repository->saveNewCredential($user, $credentialRecord, 'Key');
        $this->assertTrue($model->backup_eligible);

        // backup_eligible is a registration-time property and must never be altered
        // by a subsequent authentication, even if the library returns a different value.
        $credentialRecord->backupEligible = false;
        $this->repository->updateAfterAuthentication($model, $credentialRecord);

        $model->refresh();
        $this->assertTrue($model->backup_eligible);
    }

    public function testUpdateAfterAuthenticationUpdatesBackupState(): void
    {
        $user = User::factory()->create();
        $credentialRecord = $this->buildPublicKeyCredentialSource(backupStatus: false);

        $model = $this->repository->saveNewCredential($user, $credentialRecord, 'Key');
        $this->assertFalse($model->backup_state);

        $credentialRecord->backupStatus = true;
        $this->repository->updateAfterAuthentication($model, $credentialRecord);

        $model->refresh();
        $this->assertTrue($model->backup_state);
    }

    public function testGetPublicKeyCredentialSourceReturnsDeserializedSource(): void
    {
        $user = User::factory()->create();
        $model = $this->repository->saveNewCredential($user, $this->buildPublicKeyCredentialSource(), 'Key');

        $result = $this->repository->getPublicKeyCredentialSource($model);

        $this->assertInstanceOf(PublicKeyCredentialSource::class, $result);
        $this->assertSame(
            $model->credential_id,
            Base64UrlSafe::encodeUnpadded($result->publicKeyCredentialId),
        );
    }

    public function testDeleteRemovesModel(): void
    {
        $model = PasskeyCredential::factory()->create();

        $this->repository->delete($model);

        $this->assertModelMissing($model);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PasskeyCredentialRepository(
            app(SerializerInterface::class),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────────────────

    private function buildPublicKeyCredentialSource(
        bool $backupStatus = false,
        bool $backupEligible = false,
    ): PublicKeyCredentialSource {
        return PublicKeyCredentialSource::create(
            publicKeyCredentialId: random_bytes(32),
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: random_bytes(77),
            userHandle: 'test-user-handle',
            counter: 0,
            backupStatus: $backupStatus,
            backupEligible: $backupEligible,
        );
    }
}
