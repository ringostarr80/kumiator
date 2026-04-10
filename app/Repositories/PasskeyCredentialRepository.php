<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use Illuminate\Database\Eloquent\Collection;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\PublicKeyCredentialSource;

/**
 * Persists and retrieves WebAuthn PublicKeyCredentialSources using Eloquent.
 *
 * The library itself does not mandate any repository interface in v5.x, so this
 * class defines its own contract that is consumed by the application services.
 * The PublicKeyCredentialSource is serialised to JSON for storage so that we remain
 * decoupled from any future structural changes inside the library.
 */
final class PasskeyCredentialRepository implements PasskeyCredentialRepositoryContract
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * Find a stored credential by its UUID primary key.
     * Throws ModelNotFoundException when no matching record exists.
     */
    public function findByIdOrFail(string $id): PasskeyCredential
    {
        return PasskeyCredential::findOrFail($id);
    }

    /**
     * Find a stored credential by its Base64URL-encoded credential ID.
     * Returns null when no matching record exists.
     */
    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        return PasskeyCredential::where('credential_id', $credentialId)->first();
    }

    /**
     * Return all PasskeyCredential models belonging to a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PasskeyCredential>
     */
    public function findAllForUser(User $user): Collection
    {
        return PasskeyCredential::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Persist a new PublicKeyCredentialSource after a successful registration ceremony.
     */
    public function saveNewCredential(
        User $user,
        PublicKeyCredentialSource $credentialRecord,
        string $name,
    ): PasskeyCredential {
        $credentialId = Base64UrlSafe::encodeUnpadded($credentialRecord->publicKeyCredentialId);
        $serialized = $this->serializePublicKeyCredentialSource($credentialRecord);

        return PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'credential_public_key' => $serialized,
            'counter' => $credentialRecord->counter,
            'transports' => $credentialRecord->transports,
            'backup_eligible' => $credentialRecord->backupEligible ?? false,
            'backup_state' => $credentialRecord->backupStatus ?? false,
            'aaguid' => $credentialRecord->aaguid->toRfc4122(),
            'name' => $name,
        ]);
    }

    /**
     * Update a PublicKeyCredentialSource after a successful authentication ceremony
     * (counter and backup flags may have changed).
     */
    public function updateAfterAuthentication(
        PasskeyCredential $model,
        PublicKeyCredentialSource $credentialRecord,
    ): void {
        $model->update([
            'credential_public_key' => $this->serializePublicKeyCredentialSource($credentialRecord),
            'counter' => $credentialRecord->counter,
            'backup_state' => $credentialRecord->backupStatus ?? false,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Deserialise the stored PublicKeyCredentialSource from a PasskeyCredential model.
     * Centralises deserialization so that no other layer needs to know the storage format.
     */
    public function getPublicKeyCredentialSource(PasskeyCredential $model): PublicKeyCredentialSource
    {
        return $this->deserializePublicKeyCredentialSource($model->credential_public_key);
    }

    /**
     * Delete a passkey credential by model instance.
     */
    public function delete(PasskeyCredential $model): void
    {
        $model->deleteOrFail();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function serializePublicKeyCredentialSource(PublicKeyCredentialSource $credentialRecord): string
    {
        return $this->serializer->serialize($credentialRecord, 'json');
    }

    private function deserializePublicKeyCredentialSource(string $json): PublicKeyCredentialSource
    {
        return $this->serializer->deserialize($json, PublicKeyCredentialSource::class, 'json');
    }
}
