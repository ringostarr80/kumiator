<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Webauthn\PublicKeyCredentialSource;

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
     * Persist a new PublicKeyCredentialSource after a successful registration ceremony.
     */
    public function saveNewCredential(
        User $user,
        PublicKeyCredentialSource $credentialRecord,
        string $name,
    ): PasskeyCredential;

    /**
     * Update a PublicKeyCredentialSource after a successful authentication ceremony
     * (counter and backup flags may have changed).
     */
    public function updateAfterAuthentication(
        PasskeyCredential $model,
        PublicKeyCredentialSource $credentialRecord,
    ): void;

    /**
     * Deserialise the stored PublicKeyCredentialSource from a PasskeyCredential model.
     */
    public function getPublicKeyCredentialSource(PasskeyCredential $model): PublicKeyCredentialSource;

    /**
     * Return the PublicKeyCredentialDescriptors for all credentials of a user.
     *
     * Used to populate allowCredentials / excludeCredentials in WebAuthn options.
     *
     * @return list<\Webauthn\PublicKeyCredentialDescriptor>
     */
    public function getDescriptorsForUser(User $user): array;

    /**
     * Delete a passkey credential by model instance.
     */
    public function delete(PasskeyCredential $model): void;
}
