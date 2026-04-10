<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface PasskeyCredentialRepositoryContract
{
    /**
     * Find a stored credential by its UUID primary key.
     * Throws ModelNotFoundException when no matching record exists.
     */
    public function findByIdOrFail(string $id): PasskeyCredential;

    /**
     * Find a stored credential by its Base64URL-encoded credential ID.
     * Returns null when no matching record exists.
     */
    public function findByCredentialId(string $credentialId): ?PasskeyCredential;

    /**
     * Return all PasskeyCredential models belonging to a user.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PasskeyCredential>
     */
    public function findAllForUser(User $user): Collection;

    /**
     * Persist a new passkey credential after a successful registration ceremony.
     */
    public function saveNewCredential(User $user, NewPasskeyCredentialData $data, string $name): PasskeyCredential;

    /**
     * Update credential data after a successful authentication ceremony
     * (counter and backup flags may have changed).
     */
    public function updateAfterAuthentication(
        PasskeyCredential $model,
        string $serializedCredentialSource,
        int $counter,
        bool $backupState,
    ): void;

    /**
     * Return the serialised credential source JSON for a given model.
     */
    public function getSerializedCredentialSource(PasskeyCredential $model): string;

    /**
     * Delete a passkey credential by model instance.
     */
    public function delete(PasskeyCredential $model): void;
}
