<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Support\Facades\Config;
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

            // Bewusster Bypass der Eloquent-Events via Query-Builder: Würden wir
            // hier `each->deleteOrFail()` nutzen, würde der LogsActivity-Trait für
            // jeden Passkey einen Activity-Log-Eintrag mit Subject-Verweis auf den
            // gleich danach hart gelöschten User erzeugen — das widerspricht dem
            // DSGVO-konformen „Recht auf Vergessen" dieses Lösch-Pfads.
            PasskeyCredential::query()->where('user_id', $user->getKey())->delete();

            if (Config::string('session.driver') === 'database') {
                DB::table(Config::string('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->forceDelete();
        });

        $user->deleteProfilePhoto();
    }
}
