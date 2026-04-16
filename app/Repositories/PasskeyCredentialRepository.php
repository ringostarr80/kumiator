<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persists and retrieves WebAuthn passkey credentials using Eloquent.
 *
 * This repository is deliberately free of WebAuthn library types.
 * Serialisation and domain-specific type conversions are handled by
 * the service layer, which passes only primitives and DTOs here.
 */
final class PasskeyCredentialRepository implements PasskeyCredentialRepositoryContract
{
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
     * Persist a new passkey credential after a successful registration ceremony.
     */
    public function saveNewCredential(User $user, NewPasskeyCredentialData $data, string $name): PasskeyCredential
    {
        return PasskeyCredential::create([
            'user_id' => $user->id,
            'credential_id' => $data->credentialId,
            'credential_public_key' => $data->serializedCredentialSource,
            'counter' => $data->counter,
            'transports' => $data->transports,
            'backup_eligible' => $data->backupEligible,
            'backup_state' => $data->backupState,
            'aaguid' => $data->aaguid,
            'name' => $name,
        ]);
    }

    /**
     * Update credential data after a successful authentication ceremony
     * (counter and backup flags may have changed).
     */
    public function updateAfterAuthentication(
        PasskeyCredential $model,
        string $serializedCredentialSource,
        int $counter,
        bool $backupState,
    ): void {
        $model->updateOrFail([
            'credential_public_key' => $serializedCredentialSource,
            'counter' => $counter,
            'backup_state' => $backupState,
            'last_used_at' => now(),
        ]);
    }

    /**
     * Return the serialised credential source JSON for a given model.
     */
    public function getSerializedCredentialSource(PasskeyCredential $model): string
    {
        return $model->credential_public_key;
    }

    /**
     * Delete a passkey credential by model instance.
     */
    public function delete(PasskeyCredential $model): void
    {
        $model->deleteOrFail();
    }
}
