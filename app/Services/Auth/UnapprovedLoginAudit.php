<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Auth\Contracts\UnapprovedLoginAuditContract;
use App\Services\Concerns\RecordsRejectedLoginActivity;

/**
 * Passwort- und Passkey-Pfad schreiben `login_unapproved` über diese eine
 * Stelle, damit beide denselben Eintrag mit denselben Properties erzeugen.
 */
final class UnapprovedLoginAudit implements UnapprovedLoginAuditContract
{
    use RecordsRejectedLoginActivity;

    public function record(User $user, string $guard, ?string $email): void
    {
        $this->recordRejectedLogin($user, ActivityEvent::LOGIN_UNAPPROVED, $guard, $email);
    }
}
