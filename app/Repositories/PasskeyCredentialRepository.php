<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DataTransferObjects\NewPasskeyCredentialData;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Repositories\Contracts\PasskeyCredentialRepositoryContract;
use Illuminate\Database\Eloquent\Collection;

/**
 * Speichert und liest Passkey-Credentials über Eloquent.
 *
 * Bewusst frei von Typen der WebAuthn-Bibliothek: Serialisierung und fachliche
 * Typkonvertierungen bleiben in der Service-Schicht, die hier nur Primitive und
 * DTOs hereinreicht.
 */
final class PasskeyCredentialRepository implements PasskeyCredentialRepositoryContract
{
    public function findByIdOrFail(string $id): PasskeyCredential
    {
        return PasskeyCredential::findOrFail($id);
    }

    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        return PasskeyCredential::where('credential_id', $credentialId)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PasskeyCredential>
     */
    public function findAllForUser(User $user): Collection
    {
        return PasskeyCredential::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

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

    public function getSerializedCredentialSource(PasskeyCredential $model): string
    {
        return $model->credential_public_key;
    }

    public function updateName(PasskeyCredential $model, string $name): void
    {
        $model->updateOrFail(['name' => $name]);
    }

    public function delete(PasskeyCredential $model): void
    {
        $model->deleteOrFail();
    }
}
