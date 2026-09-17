<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Contract für den Marker und Audit-Helfer des abgeschalteten Passwort-Logins.
 *
 * Getrennt vom `UnapprovedLoginContextContract`, weil beide Marker im selben
 * Request unabhängig voneinander stehen können und ein gemeinsamer Zustand den
 * jeweils anderen Fall verschluckte. Die Begründung für die Trennung in ein
 * Interface trägt der Nachbar-Contract.
 */
interface DisabledPasswordLoginContextContract
{
    public function record(User $user, string $guard, ?string $email): void;

    public function markActive(): void;

    public function isActive(): bool;

    public function clear(): void;
}
