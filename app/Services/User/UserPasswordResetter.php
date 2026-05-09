<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\User\Contracts\UserPasswordResetterContract;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Facades\Activity;

/**
 * Setzt ein neues Passwort und schreibt anschließend einen anonymisierten
 * Audit-Eintrag im `auth`-Log (`password_reset_via_cli`).
 *
 * Warum nicht `User::sendPasswordResetNotification()` o. ä.: das ist der
 * Self-Service-Pfad (Fortify-Event-Kette inkl. `password_reset`). Der
 * CLI-Pfad ist explizit administrativ — kein Notification-Versand, kein
 * User-Token-Roundtrip; der Admin gibt das neue Passwort direkt ein.
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
    public function reset(User $user, string $newPassword): void
    {
        $user->password = Hash::make($newPassword);
        $user->saveOrFail();

        Activity::useLog('auth')
            ->event('password_reset_via_cli')
            ->causedByAnonymous()
            ->performedOn($user)
            ->log(__('app.activity_password_reset_via_cli'));
    }
}
