<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\UserPasswordResetterContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Facades\Activity;

/**
 * Setzt ein neues Passwort und schreibt anschließend einen anonymisierten
 * Audit-Eintrag im `auth`-Log (`password_reset`).
 *
 * Channel-Differenzierung: Der Event-Code `password_reset` ist
 * channel-agnostisch und wird auch vom UI-Pfad (Fortify-Event-Kette über
 * `LogAuthenticationActivityListener::handlePasswordReset`) geschrieben.
 * Die Unterscheidung „Self-Service-Reset vs. Admin-CLI-Reset" steckt im
 * Causer: UI-Pfad → Causer = handelnder User, CLI-Pfad → Causer = null
 * plus `cli_actor`-Property aus dem `ConsoleActorContext`.
 *
 * Warum nicht `User::sendPasswordResetNotification()` o. ä.: das ist der
 * Self-Service-Pfad (Fortify-Event-Kette). Der CLI-Pfad ist explizit
 * administrativ — kein Notification-Versand, kein User-Token-Roundtrip;
 * der Admin gibt das neue Passwort direkt ein.
 *
 * Warum kein automatischer `password`-Eintrag im `User`-Activity-Log: das
 * Feld steht bewusst nicht in `User::getActivitylogOptions()`-`logOnly`,
 * weil sonst jede Web-Profil-Änderung einen `user.updated`-Eintrag mit
 * leerem Diff (nur Hash, der sich immer ändert) produzieren würde — und
 * weil ein dedizierter `auth`-Eintrag fachlich präziser ist.
 *
 * Warum `causedByAnonymous()`: Spatie's `CauserResolver` würde sonst über
 * `Auth::user()` einen Default-Causer setzen — im CLI-Pfad ist das in
 * Production typischerweise `null`, aber in Tests häufig nicht (etwa wenn
 * `actingAs()` im Setup steht); ohne explizite Anonymisierung könnte ein
 * Test-User unbeabsichtigt als Causer landen. Der `cli_actor` aus
 * `ConsoleActorContext` ergänzt den Eintrag um den realen Akteur, ohne die
 * forensisch saubere Trennung „Causer = handelnder User-Account" zu
 * verwischen.
 */
final class UserPasswordResetter implements UserPasswordResetterContract
{
    /**
     * `$reenablePasswordLogin` entscheidet über den abgeschalteten Passwort-Login: Der
     * CLI-Pfad ist der einzige Weg zurück, wenn jemand sein Passwort vergisst, nachdem
     * er den Passwort-Login abgeschaltet hat — der Link zum Zurücksetzen wird solchen
     * Konten nicht mehr geschickt. Wer die Abschaltung stehen lässt, hinterlegt ein
     * Passwort, das erst nach dem Wiedereinschalten gilt; die Entscheidung gehört
     * deshalb an die aufrufende Stelle und nicht in diesen Service.
     *
     * Alles in einer Transaktion, wie beim Soft-Delete: Ein Admin, der an einem
     * fremden Konto handelt, hinterlässt entweder Passwort samt Einträgen oder
     * nichts — ein gespeichertes Passwort ohne Eintrag wäre genau die Spur, die
     * dem Audit fehlt, und der abgebrochene Befehl ließe den Admin raten, was
     * davon gegolten hat. Entschieden wird auf der gesperrten Zeile, weil die
     * übergebene Instanz älter sein kann als dieser Aufruf; eine seither erfolgte
     * Abschaltung ginge sonst ohne Eintrag wieder auf.
     */
    public function reset(User $user, string $newPassword, bool $reenablePasswordLogin): void
    {
        DB::transaction(function () use ($user, $newPassword, $reenablePasswordLogin): void {
            $account = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $wasPasswordLoginDisabled = $account->isPasswordLoginDisabled();

            $account->password = Hash::make($newPassword);

            if ($reenablePasswordLogin) {
                $account->password_login_disabled_at = null;
            }

            $account->saveOrFail();

            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::PASSWORD_RESET->value)
                ->causedByAnonymous()
                ->performedOn($account)
                ->log('');

            if (!$wasPasswordLoginDisabled || !$reenablePasswordLogin) {
                return;
            }

            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::PASSWORD_LOGIN_ENABLED->value)
                ->causedByAnonymous()
                ->performedOn($account)
                ->log('');
        });
    }
}
