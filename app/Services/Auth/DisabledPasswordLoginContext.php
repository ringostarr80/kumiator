<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Auth\Contracts\DisabledPasswordLoginContextContract;
use App\Services\Concerns\MarksRequestScope;
use App\Services\Concerns\RecordsRejectedLoginActivity;

/**
 * Audit-Schreiber für Anmeldeversuche an Konten, die den Passwort-Login
 * abgeschaltet haben, plus der Marker gegen den sonst folgenden Doppel-Eintrag.
 *
 * Der Marker wirkt wie beim `UnapprovedLoginContext` daneben: Fortify feuert
 * nach jeder abgewiesenen Passwort-Anmeldung `Illuminate\Auth\Events\Failed`,
 * und ohne Marker landete zusätzlich zum fachlich präzisen Eintrag ein
 * generischer `login_failed` im Log.
 *
 * Fachlich zählt der Eintrag als Warnsignal: Wer hier auftaucht, kennt ein
 * gültiges Passwort zu einem Konto, das sich damit nicht mehr anmelden kann —
 * ein Hinweis auf ein geleaktes oder gephishtes Passwort.
 */
final class DisabledPasswordLoginContext implements DisabledPasswordLoginContextContract
{
    use MarksRequestScope;

    use RecordsRejectedLoginActivity;

    public function record(User $user, string $guard, ?string $email): void
    {
        $this->recordRejectedLogin($user, ActivityEvent::LOGIN_PASSWORD_DISABLED, $guard, $email);
    }
}
