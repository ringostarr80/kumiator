<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\ActivityFailureReason;
use App\Models\User;
use App\Services\Auth\Contracts\SessionConfirmationAuditContract;
use Spatie\Activitylog\Facades\Activity;

/**
 * Audit-Schreiber für die Bestätigung einer laufenden Sitzung, per Passkey wie
 * per Passwort.
 *
 * Beide Wege stehen im AUTH-Kanal, weil sie dieselbe Frage beantworten: wer wann
 * vor einer sicherheitsrelevanten Änderung seine Sitzung nachgewiesen hat — und
 * wer es vergeblich versucht hat.
 */
final class SessionConfirmationAudit implements SessionConfirmationAuditContract
{
    public function recordPasskeySuccess(User $user): void
    {
        $this->record($user, ActivityEvent::PASSKEY_CONFIRMATION_SUCCEEDED);
    }

    public function recordPasskeyFailure(
        User $user,
        ?ActivityFailureReason $reason = null,
        ?string $detail = null,
    ): void {
        $this->record($user, ActivityEvent::PASSKEY_CONFIRMATION_FAILED, $reason, $detail);
    }

    public function recordPasswordFailure(User $user, ActivityFailureReason $reason): void
    {
        $this->record($user, ActivityEvent::PASSWORD_CONFIRMATION_FAILED, $reason);
    }

    /**
     * Ein durchgereichter Insert-Fehler machte aus der regulären Abweisung einen
     * 500er — und aus der erfolgreichen Bestätigung einen Fehlschlag, obwohl sie
     * in der Sitzung längst steht.
     */
    private function record(
        User $user,
        ActivityEvent $event,
        ?ActivityFailureReason $reason = null,
        ?string $detail = null,
    ): void {
        $properties = [];

        if ($reason !== null) {
            $properties['failure_reason'] = $reason->value;
        }

        if ($detail !== null) {
            $properties['failure_detail'] = $detail;
        }

        try {
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event($event->value)
                ->causedBy($user)
                ->withProperties($properties)
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
