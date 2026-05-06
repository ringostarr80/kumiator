<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * DSGVO-konformer Hard-Delete-Pfad für einen Benutzer.
 *
 * Wird sowohl vom Self-Delete (`App\Actions\Jetstream\DeleteUser`) als auch
 * vom administrativen Force-Delete-Command (`user:force-delete`) genutzt; der
 * `event`-Parameter steuert, welcher Audit-Eintrag nach dem Purge zurückbleibt
 * (`account_self_deleted` vs. `account_admin_force_deleted`). Beide Pfade
 * sollen identisches Lösch-/Purge-Verhalten haben — die Trennung dient der
 * fachlichen Unterscheidung im Audit-Trail, nicht einer abweichenden Mechanik.
 */
interface UserHardDeleterContract
{
    public function forceDelete(User $user, string $event, string $logMessage): void;
}
