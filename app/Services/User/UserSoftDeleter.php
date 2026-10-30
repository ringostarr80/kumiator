<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\EmailChangeCancellationReason;
use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Facades\Activity;

final class UserSoftDeleter implements UserSoftDeleterContract
{
    public function __construct(
        private readonly UserSessionTerminatorContract $sessionTerminator,
        private readonly UserEmailChangerContract $emailChanger,
    ) {
    }

    public function softDelete(User $user): void
    {
        $unknownPassword = User::unknownPasswordHash();

        DB::transaction(function () use ($user, $unknownPassword): void {
            // Erst die Sperre, dann jede Entscheidung — auf der gesperrten Zeile
            // statt auf der übergebenen Instanz: Die kann älter sein als dieser
            // Aufruf, und wer den Passwort-Login seither abgeschaltet hat, bliebe
            // sonst ohne Passkey und ohne Passwort-Login zurück. Es ist dieselbe
            // Sperre, die das Umschalten nimmt; so wartet der eine Weg auf den
            // anderen, statt auf dessen altem Stand zu schreiben.
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->revokeAccess($account, EmailChangeCancellationReason::ACCOUNT_SOFT_DELETED, $unknownPassword);

            $account->deleteOrFail();
        });

        // Erst nach erfolgreichem Commit: Liegt `session.connection` auf einer
        // anderen Connection als die Transaktion oben, gehörte das Session-
        // Delete nicht zu deren Rollback — ein Fehler im Block ließe den Nutzer
        // sonst ausgeloggt zurück, während das Konto aktiv bliebe.
        $this->sessionTerminator->deleteForUser($user);
    }

    public function restore(User $user): void
    {
        $unknownPassword = User::unknownPasswordHash();

        DB::transaction(function () use ($user, $unknownPassword): void {
            // Neu gelesen, weil die übergebene Instanz älter sein kann als das, was
            // ein Nachzügler geschrieben hat. Gesperrt, weil die Audit-Einträge
            // auch entstehen, wenn ein Schreiben keine Zeile trifft: Ein
            // gleichzeitiger Restore schriebe sie sonst doppelt, ein gleichzeitiger
            // Force-Delete ließe sie nach seinem Purge zurück.
            $account = User::onlyTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->revokeAccess($account, EmailChangeCancellationReason::ACCOUNT_RESTORED, $unknownPassword);

            $account->restore();
        });
    }

    private function revokeAccess(
        User $account,
        EmailChangeCancellationReason $emailChangeReason,
        string $unknownPassword,
    ): void {
        // Einzeln über das Model statt per Query: Nur so schreibt `LogsActivity`
        // je Passkey seinen `passkey_removed`-Eintrag.
        $account->passkeyCredentials->each->deleteOrFail();

        // Auch der offene Link zum Zurücksetzen verschafft nach einem `restore()`
        // wieder Zugang, solange er gilt, und dann auf ein Konto, dessen
        // Anmeldewege dieser Pfad gerade abgeräumt hat.
        Password::deleteToken($account);

        // Nach einem `restore()` gälte der Bestätigungslink wieder. Ins Konto
        // führt dann nur noch der Link zum Zurücksetzen, und der geht an die
        // Adresse, die der Bestätigungslink setzt.
        $this->emailChanger->cancelOpenChange($account, $emailChangeReason);

        // Alte Sessions prüft `AuthenticateSession` gegen den Passwort-Hash, alte
        // Recaller-Cookies der Guard, und das bei jedem Session-Treiber. Der
        // Terminator erreicht nur Sessions in der Datenbank. Bliebe das
        // Passwort, meldeten beide nach einem `restore()` wieder an. Eigenes
        // Speichern, weil das Löschen nur `deleted_at` und `updated_at` schreibt;
        // das `save()` im Restore nähme die Änderungen auch so mit.
        // Gehasht wird vor der Transaktion: Unter SQLite hielte sie während bcrypt
        // schon die Schreibsperre auf die ganze Datenbank, und jede andere
        // Transaktion, die erst liest und dann schreibt, scheiterte so lange sofort.
        $account->password = $unknownPassword;
        $this->reopenPasswordLogin($account);
        $account->saveOrFail();
    }

    /**
     * Der abgeschaltete Passwort-Login setzt einen Passkey voraus, und die nimmt
     * dieser Pfad alle mit. Bliebe die Abschaltung stehen, ließe ein späteres
     * `restore()` das Konto ohne jeden Anmeldeweg zurück: Das Passwort weist der
     * Login ab, ein Passkey ist nicht mehr da, und den Link zum Zurücksetzen
     * bekommen solche Konten nicht geschickt.
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

        // Direkt statt über das Model: Dessen Methode verwirft ein verdächtiges
        // Passwort, und vor dem Speichern gilt auch das Ersatzpasswort als solches.
        $user->password_login_disabled_at = null;

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_LOGIN_ENABLED->value)
            ->causedByAnonymous()
            ->performedOn($user)
            ->log('');
    }
}
