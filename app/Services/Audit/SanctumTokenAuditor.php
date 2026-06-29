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
        $this->write(ActivityEvent::API_TOKEN_CREATED, $subject, $token, false);
    }

    public function recordRevoked(User $subject, Model $token): void
    {
        $this->write(ActivityEvent::API_TOKEN_REVOKED, $subject, $token, false);
    }

    public function recordRevokedAnonymously(User $subject, Model $token): void
    {
        $this->write(ActivityEvent::API_TOKEN_REVOKED, $subject, $token, true);
    }

    private function write(ActivityEvent $event, User $subject, Model $token, bool $anonymous): void
    {
        $activity = Activity::useLog(ActivityChannel::AUTH->value)
            ->event($event->value);

        if ($anonymous) {
            $activity->causedByAnonymous();
        } else {
            $activity->causedBy($subject);
        }

        // Kein try/catch: im CLI-Lösch-Pfad läuft der Insert in einer
        // Transaktion, ein verschluckter Fehler verhinderte dort den
        // gewollten Rollback des Token-Deletes.
        $activity
            ->performedOn($subject)
            ->withProperties([
                'token_id' => $token->getKey(),
                'token_name' => $token->getAttribute('name'),
                'abilities' => $token->getAttribute('abilities'),
            ])
            ->log($event->description());
    }
}
