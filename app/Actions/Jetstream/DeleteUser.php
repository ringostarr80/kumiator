<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     *
     * Self-Delete ist bewusst ein Hard-Delete: DSGVO-konformes „Recht auf Vergessen".
     * Dadurch werden auch Passkeys und Sessions hart entfernt, damit nach dem
     * Löschen kein Zugriffsmittel mehr existiert. Administrative Löschungen
     * laufen separat über den Console-Command `user:delete` und verwenden einen
     * Soft-Delete, um die fachliche Historie zu erhalten.
     */
    public function delete(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            $user->tokens->each->deleteOrFail();
            PasskeyCredential::query()->where('user_id', $user->getKey())->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $user->forceDelete();
        });

        $user->deleteProfilePhoto();
    }
}
