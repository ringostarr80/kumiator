<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Contracts\AuthorizationAuditable;
use App\Models\User;
use App\Services\Audit\Contracts\AuthorizationAuditorContract;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;

/**
 * Der eine Ort, an dem das „403 protokollieren"-Muster lebt: schreibt für
 * abgelehnte Autorisierungen einen `authorization_denied`-Eintrag, statt das
 * Muster pro Aufrufstelle zu kopieren. Zwei Einstiege speisen denselben
 * Schreibpfad:
 *  - `record()` hängt an `Gate::after` und auditiert nur, wenn das geprüfte
 *    Subjekt sich per `AuthorizationAuditable` anmeldet — sonst flutete jeder
 *    Sichtbarkeits-`@can` das Log.
 *  - `recordSubjectlessDenial()` für Zugriffsversuche ohne Subjekt (z. B. die
 *    reine Anzeige-Permission `activity-log.view`): die kann nicht über
 *    `Gate::after` laufen, weil dort jeder subjektlose Check durchliefe — ob ein
 *    echter Zugriffsversuch vorliegt, weiß nur die Aufrufstelle.
 */
final class AuthorizationAuditor implements AuthorizationAuditorContract
{
    /**
     * @param array<array-key, mixed> $arguments die dem Gate übergebenen Argumente
     */
    public function record(?Authenticatable $causer, string $ability, mixed $result, array $arguments): void
    {
        if (!($causer instanceof User) || $this->isGranted($result)) {
            return;
        }

        $subject = $this->auditableSubject($arguments);

        if ($subject === null) {
            return;
        }

        $this->writeDenied($causer, $ability, $subject);
    }

    public function recordSubjectlessDenial(?Authenticatable $causer, string $ability): void
    {
        if (!($causer instanceof User)) {
            return;
        }

        $this->writeDenied($causer, $ability, null);
    }

    private function isGranted(mixed $result): bool
    {
        if ($result instanceof Response) {
            return $result->allowed();
        }

        return $result === true;
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function auditableSubject(array $arguments): ?Model
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof AuthorizationAuditable && $argument instanceof Model) {
                return $argument;
            }
        }

        return null;
    }

    /**
     * Subjektlose Ablehnung übergibt `$subject = null` → `target_type`/
     * `target_id_hash` bleiben null; mit Subjekt werden beide daraus abgeleitet.
     */
    private function writeDenied(User $causer, string $ability, ?Model $subject): void
    {
        // getKey() ist mixed typisiert; reale Primärschlüssel sind String/Int.
        $key = $subject?->getKey();

        try {
            Activity::useLog(ActivityChannel::SECURITY->value)
                ->event(ActivityEvent::AUTHORIZATION_DENIED->value)
                ->causedBy($causer)
                ->withProperties([
                    'ability' => $ability,
                    // Morph-Alias statt FQCN — konsistent zu subject_type/causer_type.
                    'target_type' => $subject?->getMorphClass(),
                    'target_id_hash' => AuditIdHasher::hash($key),
                ])
                ->log(ActivityEvent::AUTHORIZATION_DENIED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
