<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Laravel\Sanctum\PersonalAccessToken;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     *
     * Self-Delete ist bewusst ein Hard-Delete: DSGVO-konformes „Recht auf Vergessen".
     * Dadurch werden auch Sanctum-Tokens, Passkeys und Sessions hart entfernt,
     * damit nach dem Löschen kein Zugriffsmittel mehr existiert. Administrative
     * Löschungen laufen separat über den Console-Command `user:delete` und
     * verwenden einen Soft-Delete, um die fachliche Historie zu erhalten.
     *
     * Hinweis zum Session-Treiber: Die explizite Session-Löschung wirkt nur bei
     * `session.driver = database` (aktueller Projekt-Default). Bei Redis/File/
     * Cookie bleiben Session-Payloads im Backend liegen, bis ihre TTL abläuft.
     * Für den Auth-Schutz reicht das, weil der User hart gelöscht ist und
     * `EloquentUserProvider::retrieveById()` keinen User mehr liefert. Wird der
     * Treiber gewechselt, muss diese Annahme neu bewertet werden (ggf. treiber-
     * spezifisches Purge, DSGVO-Sicht auf Session-Payloads).
     */
    public function delete(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            // Bewusster Bypass der Eloquent-Events via Query-Builder für ALLE
            // Lösch-Aktionen dieses Pfads: Würde z. B. `tokens->each->deleteOrFail()`
            // oder `passkeyCredentials->each->deleteOrFail()` laufen, würde der
            // LogsActivity-Trait (bzw. zukünftige Observer auf Tokens) Activity-
            // Log-Einträge mit Causer/Subject-Verweis auf den gleich danach hart
            // gelöschten User erzeugen — das widerspricht dem DSGVO-konformen
            // „Recht auf Vergessen" dieses Lösch-Pfads. Im Admin-Pfad (siehe
            // `App\Console\Commands\User\Delete`) ist die Semantik bewusst
            // umgekehrt: dort sollen die Widerrufe im Activity-Log dokumentiert
            // bleiben.
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->delete();

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
