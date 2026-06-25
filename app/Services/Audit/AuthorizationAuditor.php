<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\Contracts\AuthorizationAuditable;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;

/**
 * Der eine Ort, an dem das „403 protokollieren"-Muster lebt: hängt an
 * `Gate::after` und schreibt für abgelehnte Autorisierungen einen
 * `authorization_denied`-Eintrag, statt das Muster pro Aufrufstelle zu kopieren.
 *
 * Auditiert wird nur, wenn das geprüfte Subjekt sich per `AuthorizationAuditable`
 * dazu anmeldet — sonst flutete jeder Sichtbarkeits-`@can` das Log. Der
 * subjektlose Zugriffsschutz (`activity-log.view`) bleibt darum bewusst an seiner
 * Aufrufstelle, die als Einzige weiß, dass ein echter Zugriffsversuch vorliegt.
 */
final class AuthorizationAuditor
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

        $this->write($causer, $ability, $subject);
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

    private function write(User $causer, string $ability, Model $subject): void
    {
        // getKey() ist mixed typisiert; reale Primärschlüssel sind String/Int.
        $key = $subject->getKey();

        try {
            Activity::useLog(ActivityChannel::SECURITY->value)
                ->event(ActivityEvent::AUTHORIZATION_DENIED->value)
                ->causedBy($causer)
                ->withProperties([
                    'ability' => $ability,
                    // Morph-Alias statt FQCN — konsistent zu subject_type/causer_type.
                    'target_type' => $subject->getMorphClass(),
                    // SHA-256 der ID statt Klartext (DSGVO-Datenminimierung).
                    'target_id_hash' => is_scalar($key) ? hash('sha256', (string) $key) : null,
                ])
                ->log(ActivityEvent::AUTHORIZATION_DENIED->description());
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
