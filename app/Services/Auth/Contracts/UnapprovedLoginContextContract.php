<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Contract für den `UnapprovedLoginContext`-Marker und Audit-Helfer.
 *
 * Die Trennung in ein Interface ist nötig, weil Controller laut Architektur-
 * Regel (`ControllersAreIndependentTest`) keine konkreten Services kennen
 * dürfen — DI erfolgt ausschließlich über Contracts. Der Passkey-Pfad
 * (`PasskeyAuthenticationController`) injiziert dieses Contract, der
 * Passwort-Pfad (`FortifyServiceProvider`) ruft die konkrete Implementation
 * statisch — Provider sind von dieser Schichten-Regel ausgenommen.
 */
interface UnapprovedLoginContextContract
{
    /**
     * Schreibt einen `login_unapproved`-Audit-Eintrag und setzt den
     * Request-scoped Marker, damit der nachfolgende `Failed`-Event nicht
     * zusätzlich als generischer `login_failed`-Eintrag landet.
     */
    public function record(User $user, string $guard, ?string $email): void;
}
