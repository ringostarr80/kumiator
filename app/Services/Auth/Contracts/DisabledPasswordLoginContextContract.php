<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Contract für den Marker und Audit-Helfer des abgeschalteten Passwort-Logins.
 *
 * Die Begründung für die Trennung in ein Interface trägt der
 * `UnapprovedLoginAuditContract`.
 */
interface DisabledPasswordLoginContextContract
{
    public function record(User $user, string $guard, ?string $email): void;

    public function markActive(): void;

    public function isActive(): bool;

    public function clear(): void;
}
