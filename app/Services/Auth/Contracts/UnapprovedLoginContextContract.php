<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Contract für den `UnapprovedLoginContext`-Marker und Audit-Helfer.
 *
 * Die Trennung in ein Interface ist nötig, weil Controller laut Architektur-
 * Regel (`ControllersAreIndependentTest`) keine konkreten Services kennen
 * dürfen — DI erfolgt ausschließlich über Contracts. Der Marker-Zustand
 * wandert über die scoped Container-Instanz: alle Pfade (Passkey-Controller,
 * Passwort-Closure, `Failed`-Listener) müssen dieses Contract auflösen,
 * damit sie dieselbe Request-Instanz sehen.
 */
interface UnapprovedLoginContextContract
{
    /**
     * Schreibt einen `login_unapproved`-Audit-Eintrag und setzt den
     * Request-scoped Marker, damit der nachfolgende `Failed`-Event nicht
     * zusätzlich als generischer `login_failed`-Eintrag landet.
     */
    public function record(User $user, string $guard, ?string $email): void;

    public function markActive(): void;

    public function isActive(): bool;

    public function clear(): void;
}
