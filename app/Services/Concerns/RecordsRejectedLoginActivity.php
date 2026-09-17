<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Audit\AuditEmailHasher;
use Spatie\Activitylog\Facades\Activity;

/**
 * Gemeinsamer Schreibpfad für Anmeldeversuche, die an einer Eigenschaft des
 * Kontos scheitern, obwohl die vorgelegten Credentials stimmen.
 *
 * Causer und Subject sind deshalb der User: Die Identität ist verifiziert.
 * Der `email_hash` daneben hält die Korrelation mit den anonymen
 * `login_failed`-Einträgen offen, ohne die Adresse im Klartext abzulegen —
 * Reports können darüber korrelieren, ohne den Causer aufzulösen.
 */
trait RecordsRejectedLoginActivity
{
    /**
     * Ein durchgereichter Insert-Fehler machte aus der regulären Abweisung
     * einen 500er und verriete damit, dass das Konto existiert. Die Antwort
     * muss unabhängig davon dieselbe sein, ob der Audit-Sink erreichbar ist.
     */
    private function recordRejectedLogin(User $user, ActivityEvent $event, string $guard, ?string $email): void
    {
        $properties = ['guard' => $guard];

        $emailHash = AuditEmailHasher::hash($email);

        if ($emailHash !== null) {
            $properties['email_hash'] = $emailHash;
        }

        try {
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event($event->value)
                ->causedBy($user)
                ->performedOn($user)
                ->withProperties($properties)
                ->log('');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
