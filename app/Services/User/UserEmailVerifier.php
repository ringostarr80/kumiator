<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\User\Contracts\UserEmailVerifierContract;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Facades\Activity;

/**
 * Setzt `email_verified_at` und schreibt anschließend einen anonymisierten
 * Audit-Eintrag im `auth`-Log (`email_verified_via_cli`).
 *
 * Warum keine `markEmailAsVerified()` aus dem Trait: das interne `save()`
 * (ohne -OrFail) verschluckt stille Persistierungs-Fehler. CLAUDE-Regel
 * verlangt `saveOrFail()`, also setzen wir das Feld explizit und sichern
 * mit harter Exception ab.
 *
 * Warum `causedByAnonymous()`: Spatie's `CauserResolver` würde sonst über
 * `Auth::user()` einen Default-Causer setzen — im CLI-Pfad ist das aktuell
 * `null`, aber Tests nutzen `actingAs()` häufig im Setup; ohne explizite
 * Anonymisierung könnte ein angemeldeter Test-User unbeabsichtigt als Causer
 * landen. Punkt #5 der Activity-Log-Roadmap wird hier später einen echten
 * CLI-Actor (OS-User aus `posix_getpwuid()`) als zusätzliches Property
 * nachreichen — ohne den Event-Code oder die Subject-Beziehung zu brechen.
 */
final class UserEmailVerifier implements UserEmailVerifierContract
{
    public function verify(User $user): void
    {
        $user->email_verified_at = Carbon::now();
        $user->saveOrFail();

        Activity::useLog('auth')
            ->event('email_verified_via_cli')
            ->causedByAnonymous()
            ->performedOn($user)
            ->log(__('app.activity_email_verified_via_cli'));
    }
}
