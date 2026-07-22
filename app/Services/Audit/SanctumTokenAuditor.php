<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Audit\Contracts\SanctumTokenAuditorContract;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;

final class SanctumTokenAuditor implements SanctumTokenAuditorContract
{
    public function recordCreated(User $subject, Model $token): void
    {
        $this->writeResilient(ActivityEvent::API_TOKEN_CREATED, $subject, $token);
    }

    /**
     * @param list<string> $previousAbilities
     */
    public function recordPermissionsChanged(User $subject, Model $token, array $previousAbilities): void
    {
        $this->writeResilient(
            ActivityEvent::API_TOKEN_PERMISSIONS_CHANGED,
            $subject,
            $token,
            ['previous_abilities' => $previousAbilities],
        );
    }

    public function recordRevoked(User $subject, Model $token): void
    {
        $this->writeResilient(ActivityEvent::API_TOKEN_REVOKED, $subject, $token);
    }

    public function recordRevokedAnonymously(User $subject, Model $token): void
    {
        // Bewusst ohne Auffangen: der CLI-Lösch-Pfad ruft dies innerhalb einer
        // Transaktion auf, in der Token-Delete und dieser Insert gemeinsam
        // klammern — ein verschluckter Fehler verhinderte den gewollten Rollback.
        $this->write(ActivityEvent::API_TOKEN_REVOKED, $subject, $token, true);
    }

    /**
     * Die UI-Pfade auditieren erst, nachdem der Parent-Aufruf die Token-Mutation
     * schon committet hat. Ein hier geworfener Insert-Fehler dürfte diese
     * abgeschlossene Operation nicht als 500 verkleiden — deshalb melden statt
     * durchreichen, symmetrisch zum `AuthorizationAuditor`.
     *
     * @param array<string, mixed> $extraProperties
     */
    private function writeResilient(
        ActivityEvent $event,
        User $subject,
        Model $token,
        array $extraProperties = [],
    ): void {
        try {
            $this->write($event, $subject, $token, false, $extraProperties);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $extraProperties
     */
    private function write(
        ActivityEvent $event,
        User $subject,
        Model $token,
        bool $anonymous,
        array $extraProperties = [],
    ): void {
        $activity = Activity::useLog(ActivityChannel::AUTH->value)
            ->event($event->value);

        if ($anonymous) {
            $activity->causedByAnonymous();
        } else {
            $activity->causedBy($subject);
        }

        $activity
            ->performedOn($subject)
            ->withProperties([
                'token_id' => $token->getKey(),
                'token_name' => $token->getAttribute('name'),
                'abilities' => $token->getAttribute('abilities'),
                ...$extraProperties,
            ])
            ->log('');
    }
}
