<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\SelfEmailVerifierContract;
use App\Services\User\Exceptions\SelfEmailVerificationFailedException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Facades\Activity;

/**
 * Setzt `email_verified_at` für den self-service Verify-Link und dispatcht
 * den `Verified`-Event — das anschließende `email_verified`-Audit übernimmt
 * der `LogAuthenticationActivityListener::handleVerified()`.
 *
 * Fehlversuche werden hier direkt geloggt (`email_verification_failed`),
 * weil sie keinen Event-Trigger haben und sonst stumm bleiben würden.
 * Causer ist immer anonym: bei `user_not_found` ist gar kein User auflösbar;
 * bei `hash_mismatch` wäre der gefundene User zwar bekannt, aber die
 * Tatsache, dass der Hash NICHT passt, deutet auf einen anderen Akteur hin
 * (Tippfehler in der URL, alte Mail nach E-Mail-Wechsel, oder Angreifer).
 *
 * Warum `saveOrFail()` statt `markEmailAsVerified()`: Der Trait nutzt
 * intern `save()` ohne -OrFail — stille Persistierungsfehler wären gegen
 * CLAUDE-Regel. Wir setzen das Feld explizit und versichern mit harter
 * Exception (symmetrisch zu {@see UserEmailVerifier}).
 */
final class SelfEmailVerifier implements SelfEmailVerifierContract
{
    public function verify(int $userId, string $hash): User
    {
        $user = User::find($userId);

        if ($user === null) {
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::EMAIL_VERIFICATION_FAILED->value)
                ->causedByAnonymous()
                ->withProperties([
                    'reason' => 'user_not_found',
                    'attempted_user_id' => $userId,
                ])
                ->log('');

            throw new SelfEmailVerificationFailedException();
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            // Forensisch wertvoll: `performedOn=user` macht sichtbar, gegen
            // welches Konto die ungültigen Hashes laufen — Indikator für
            // gezieltes Probieren oder einen alten Link nach E-Mail-Wechsel.
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::EMAIL_VERIFICATION_FAILED->value)
                ->causedByAnonymous()
                ->performedOn($user)
                ->withProperties(['reason' => 'hash_mismatch'])
                ->log('');

            throw new SelfEmailVerificationFailedException();
        }

        $user->email_verified_at = Carbon::now();
        $user->saveOrFail();

        event(new Verified($user));

        return $user;
    }
}
