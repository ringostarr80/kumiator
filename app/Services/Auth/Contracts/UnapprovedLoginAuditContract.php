<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Die Trennung in ein Interface ist nötig, weil Controller laut Architektur-
 * Regel (`ControllersAreIndependentTest`) keine konkreten Services kennen
 * dürfen — DI erfolgt ausschließlich über Contracts.
 */
interface UnapprovedLoginAuditContract
{
    /**
     * Geteilter Schreibpfad für den `login_unapproved`-Audit-Eintrag, den
     * Passwort- und Passkey-Pfad nutzen.
     */
    public function record(User $user, string $guard, ?string $email): void;
}
