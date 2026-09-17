<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Audit\Contracts\SanctumTokenAuditorContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Facades\Activity;

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
 * schreibt. Anschließend ein offener Link zum Zurücksetzen. Danach fällt ein
 * abgeschalteter Passwort-Login, weil das Konto mit seinen Passkeys sonst jeden
 * Anmeldeweg verlöre. Zuletzt der Soft-Delete des Users selbst. Alles in einer
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
            // Erst die Sperre, dann jede Entscheidung — auf der gesperrten Zeile
            // statt auf der übergebenen Instanz: Die kann älter sein als dieser
            // Aufruf, und wer den Passwort-Login seither abgeschaltet hat, bliebe
            // sonst ohne Passkey und ohne Passwort-Login zurück. Es ist dieselbe
            // Sperre, die das Umschalten nimmt; so wartet der eine Weg auf den
            // anderen, statt auf dessen altem Stand zu schreiben.
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Sanctums `PersonalAccessToken` hat kein `LogsActivity`-Trait —
            // ohne expliziten Eintrag verschwänden die Tokens beim Admin-Delete
            // stumm. Reihenfolge bewusst „delete → log": `deleteOrFail()` leert
            // nur die DB-Zeile, `$token` behält seine Attribute in-Memory und
            // bleibt fürs Logging lesbar. Beides klammert die Transaktion —
            // bricht der Audit-Insert, wird auch das Delete zurückgerollt.
            foreach ($account->tokens as $token) {
                $token->deleteOrFail();

                $this->tokenAuditor->recordRevokedAnonymously($account, $token);
            }

            $account->passkeyCredentials->each->deleteOrFail();

            // Auch der offene Link zum Zurücksetzen verschafft nach einem `restore()`
            // wieder Zugang — bis zu eine Stunde nach seiner Ausstellung, und dann auf
            // ein Konto, dessen Anmeldewege dieser Pfad gerade abgeräumt hat.
            Password::deleteToken($account);

            $this->reopenPasswordLogin($account);

            $account->deleteOrFail();
        });

        // Erst nach erfolgreichem Commit: Liegt `session.connection` auf einer
        // anderen Connection als die Transaktion oben, gehörte das Session-
        // Delete nicht zu deren Rollback — ein Fehler im Block ließe den Nutzer
        // sonst ausgeloggt zurück, während das Konto aktiv bliebe.
        $this->sessionTerminator->deleteForUser($user);
    }

    /**
     * Der abgeschaltete Passwort-Login setzt einen Passkey voraus, und die nimmt
     * dieser Pfad alle mit. Bliebe die Abschaltung stehen, ließe ein späteres
     * `restore()` das Konto ohne jeden Anmeldeweg zurück: Das Passwort weist der
     * Login ab, ein Passkey ist nicht mehr da, und den Link zum Zurücksetzen
     * bekommen solche Konten nicht geschickt.
     *
     * Das Passwort von vor der Abschaltung fällt mit, weil die Abschaltung gerade
     * den Verdacht gegen dieses Passwort festhält; ein seither gesetztes bleibt.
     *
     * Der eigene Audit-Eintrag ist nötig, weil die Spalte nicht in der `logOnly`-
     * Allowlist des Users steht — der Passwort-Login spränge sonst spurlos wieder
     * auf. Anonym wie die übrigen Einträge dieses Pfades; den handelnden Admin
     * nennt das `cli_actor`-Property.
     */
    private function reopenPasswordLogin(User $user): void
    {
        if (!$user->isPasswordLoginDisabled()) {
            return;
        }

        // Eigenes Speichern statt einer Zuweisung vor dem Delete: Der Soft-Delete
        // schreibt ausschließlich `deleted_at` und `updated_at`, alles andere
        // bliebe liegen.
        $user->reopenPasswordLogin();
        $user->saveOrFail();

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_LOGIN_ENABLED->value)
            ->causedByAnonymous()
            ->performedOn($user)
            ->log('');
    }
}
