<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\Audit\Contracts\SanctumTokenAuditorContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Support\Facades\DB;

/**
 * Administrativer Soft-Delete eines Benutzers.
 *
 * Siehe {@see UserSoftDeleterContract} für den Gesamt-Kontrakt und die
 * Abgrenzung zum Hard-Delete-Pfad.
 *
 * Reihenfolge in der Transaktion: pro Sanctum-Token das DB-Delete und
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
 * Die Session-Reihen (nur bei `session.driver = database`) entfernt der
 * Terminator bewusst erst nach dem Commit.
 *
 * Audit-Symmetrie zum UI-Pfad: Beide Pfade schreiben den `api_token_revoked`-
 * Eintrag über denselben `SanctumTokenAuditor`, sodass Event-Form und
 * Properties strukturell nicht auseinanderlaufen. Auf der CLI ist der Causer
 * anonym; der handelnde Admin steckt im `cli_actor`-Property, das der
 * `CaptureConsoleActorListener` an jeden während der Command-Ausführung
 * entstehenden Eintrag anhängt.
 */
final class UserSoftDeleter implements UserSoftDeleterContract
{
    public function __construct(
        private readonly UserSessionTerminatorContract $sessionTerminator,
        private readonly SanctumTokenAuditorContract $tokenAuditor,
    ) {
    }

    public function softDelete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Sanctums `PersonalAccessToken` hat kein `LogsActivity`-Trait —
            // ohne expliziten Eintrag verschwänden die Tokens beim Admin-Delete
            // stumm. Reihenfolge bewusst „delete → log": `deleteOrFail()` leert
            // nur die DB-Zeile, `$token` behält seine Attribute in-Memory und
            // bleibt fürs Logging lesbar. Beides klammert die Transaktion —
            // bricht der Audit-Insert, wird auch das Delete zurückgerollt.
            foreach ($user->tokens as $token) {
                $token->deleteOrFail();

                $this->tokenAuditor->recordRevokedAnonymously($user, $token);
            }

            $user->passkeyCredentials->each->deleteOrFail();

            $user->deleteOrFail();
        });

        // Erst nach erfolgreichem Commit: Liegt `session.connection` auf einer
        // anderen Connection als die Transaktion oben, gehörte das Session-
        // Delete nicht zu deren Rollback — ein Fehler im Block ließe den Nutzer
        // sonst ausgeloggt zurück, während das Konto aktiv bliebe.
        $this->sessionTerminator->deleteForUser($user);
    }
}
