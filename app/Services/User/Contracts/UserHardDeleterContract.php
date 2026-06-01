<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Enums\ActivityEvent;
use App\Models\User;

/**
 * DSGVO-konformer Hard-Delete-Pfad für einen Benutzer.
 *
 * Wird sowohl vom Self-Delete (`App\Actions\Jetstream\DeleteUser`) als auch
 * vom administrativen Force-Delete-Command (`user:force-delete`) genutzt; der
 * `event`-Parameter steuert, welcher Audit-Eintrag nach dem Purge zurückbleibt
 * ({@see ActivityEvent::ACCOUNT_SELF_DELETED} vs.
 * {@see ActivityEvent::ACCOUNT_ADMIN_FORCE_DELETED}). Beide Pfade sollen
 * identisches Lösch-/Purge-Verhalten haben — die Trennung dient der fachlichen
 * Unterscheidung im Audit-Trail, nicht einer abweichenden Mechanik. Die
 * Klartext-Beschreibung wird aus dem Event abgeleitet ({@see ActivityEvent::description()}).
 */
interface UserHardDeleterContract
{
    public function forceDelete(User $user, ActivityEvent $event): void;
}
