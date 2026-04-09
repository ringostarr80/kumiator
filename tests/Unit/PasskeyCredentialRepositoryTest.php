<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\PasskeyCredentialRepository;
use App\Services\WebAuthn\WebAuthnServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

final class PasskeyCredentialRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PasskeyCredentialRepository $repository;

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
            app(WebAuthnServerService::class)->getSerializer(),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper
    // ──────────────────────────────────────────────────────────────────────────

    private function buildPublicKeyCredentialSource(): PublicKeyCredentialSource
    {
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
        );
    }
}
