<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface PasskeyCredentialRepositoryContract
{
    public function findByIdOrFail(string $id): PasskeyCredential;

    public function findByCredentialId(string $credentialId): ?PasskeyCredential;

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PasskeyCredential>
     */
    public function findAllForUser(User $user): Collection;

    public function saveNewCredential(User $user, NewPasskeyCredentialData $data, string $name): PasskeyCredential;

    public function updateAfterAuthentication(
        PasskeyCredential $model,
        string $serializedCredentialSource,
        int $counter,
        bool $backupState,
    ): void;

    public function getSerializedCredentialSource(PasskeyCredential $model): string;

    public function updateName(PasskeyCredential $model, string $name): void;

    public function delete(PasskeyCredential $model): void;
}
