<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\UserEmailVerifierContract;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Facades\Activity;

/**
 * Setzt `email_verified_at` und schreibt anschließend einen anonymisierten
 * Audit-Eintrag im `auth`-Log (`email_verified`).
 *
 * Channel-Differenzierung: Der Event-Code `email_verified` ist
 * channel-agnostisch und wird auch vom UI-/Self-Verify-Pfad geschrieben
 * (`LogAuthenticationActivityListener::handleVerified`, ausgelöst über
 * das `Verified`-Event aus dem `VerifyEmailController`/`SelfEmailVerifier`).
 * Die Unterscheidung „Self-Verify vs. Admin-CLI-Verify" steckt im Causer:
 * UI-Pfad → Causer = User, CLI-Pfad → Causer = null plus `cli_actor`-
 * Property aus dem `ConsoleActorContext`.
 *
 * Warum keine `markEmailAsVerified()` aus dem Trait: das interne `save()`
 * (ohne -OrFail) verschluckt stille Persistierungs-Fehler. CLAUDE-Regel
 * verlangt `saveOrFail()`, also setzen wir das Feld explizit und sichern
 * mit harter Exception ab.
 *
 * Warum kein `Verified`-Event-Dispatch (anders als im `SelfEmailVerifier`):
 * Der Listener würde den Eintrag mit `causedBy($user)` schreiben — im
 * CLI-Pfad falsch, weil der User die Aktion nicht selbst ausgelöst hat.
 * Direktes Schreiben mit `causedByAnonymous()` ist hier die einzige
 * Variante, die das Audit-Bild korrekt hält.
 */
final class UserEmailVerifier implements UserEmailVerifierContract
{
    public function verify(User $user): void
    {
        $user->email_verified_at = Carbon::now();
        $user->saveOrFail();

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_VERIFIED->value)
            ->causedByAnonymous()
            ->performedOn($user)
            ->log(ActivityEvent::EMAIL_VERIFIED->description());
    }
}
