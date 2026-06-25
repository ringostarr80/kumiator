<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Facades\Activity;

/**
 * Administrativer Soft-Delete eines Benutzers.
 *
 * Siehe {@see UserSoftDeleterContract} für den Gesamt-Kontrakt und die
 * Abgrenzung zum Hard-Delete-Pfad.
 *
 * Reihenfolge in der Transaktion: zuerst Session-Reihen entfernen (nur bei
 * `session.driver = database`), dann pro Sanctum-Token das DB-Delete und
 * direkt im Anschluss der `api_token_revoked`-Eintrag (Past-Tense-Semantik:
 * der Eintrag beschreibt einen abgeschlossenen Vorgang; die In-Memory-
 * Eloquent-Instanz behält ihre Attribute auch nach `deleteOrFail()`).
 * Anschließend Passkey-Credentials per `each->deleteOrFail()` — letzteres
 * läuft mit Eloquent-Events, sodass das `LogsActivity`-Trait des
 * `PasskeyCredential`-Models die `passkey_removed`-Einträge automatisch
 * schreibt. Zuletzt der Soft-Delete des Users selbst. Alles in einer
 * `DB::transaction()`: wirft ein Delete, wird der zugehörige Audit-Insert
 * mit zurückgerollt.
 *
 * Audit-Symmetrie zum UI-Pfad: `ApiTokenManager::deleteApiToken()` schreibt
 * dieselbe Event-Form (`api_token_revoked`, mit `token_id`/`token_name`/
 * `abilities`). Auf der CLI ist der Causer anonym; der handelnde Admin steckt
 * im `cli_actor`-Property, das der `CaptureConsoleActorListener` an jeden
 * während der Command-Ausführung entstehenden Eintrag anhängt.
 */
final class UserSoftDeleter implements UserSoftDeleterContract
{
    public function __construct(private readonly UserSessionTerminatorContract $sessionTerminator)
    {
    }

    public function softDelete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->sessionTerminator->deleteForUser($user);

            // Symmetrie zum UI-Pfad (`ApiTokenManager::deleteApiToken()`):
            // Sanctums `PersonalAccessToken` hat kein `LogsActivity`-Trait,
            // ohne expliziten Eintrag würden die Tokens beim Admin-Delete
            // stumm verschwinden. Causer ist anonym (kein authentifizierter
            // Akteur auf der CLI); der Admin-Kontext steckt im `cli_actor`-
            // Property aus dem Console-Listener.
            //
            // Reihenfolge bewusst „delete → log" (analog UI-Pfad): der Event-
            // Code ist Vergangenheitsform und beschreibt einen abgeschlossenen
            // Vorgang. `deleteOrFail()` leert nur die DB-Zeile; `$token`
            // behält seine Attribute in-Memory und kann fürs Logging weiter
            // gelesen werden. Transaktion klammert beide Schritte: bricht
            // der Insert, wird auch das Delete zurückgerollt.
            foreach ($user->tokens as $token) {
                $token->deleteOrFail();

                Activity::useLog(ActivityChannel::AUTH->value)
                    ->event(ActivityEvent::API_TOKEN_REVOKED->value)
                    ->causedByAnonymous()
                    ->performedOn($user)
                    ->withProperties([
                        'token_id' => $token->getKey(),
                        'token_name' => $token->name,
                        'abilities' => $token->abilities,
                    ])
                    ->log(ActivityEvent::API_TOKEN_REVOKED->description());
            }

            $user->passkeyCredentials->each->deleteOrFail();

            $user->deleteOrFail();
        });
    }
}
