<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\EmailVerificationResenderContract;
use Spatie\Activitylog\Facades\Activity;

/**
 * Versendet die Verifizierungs-Mail erneut und protokolliert die Anforderung.
 * Siehe {@see EmailVerificationResenderContract} für den Kontext.
 *
 * Bewusst KEINE Forensik-Properties (IP/User-Agent): Anders als beim anonymen
 * `password_reset_requested` (Gast auf `/forgot-password`) ist der Anfordernde
 * hier authentifiziert — `causedBy`/`performedOn` identifizieren ihn eindeutig,
 * und die übrigen authentifizierten auth-Events (`password_updated`,
 * `email_verified`) tragen ebenfalls keine IP/UA (DSGVO-Datenminimierung).
 */
final class EmailVerificationResender implements EmailVerificationResenderContract
{
    public function resend(User $user): void
    {
        $user->sendEmailVerificationNotification();

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_VERIFICATION_REQUESTED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::EMAIL_VERIFICATION_REQUESTED->description());
    }
}
