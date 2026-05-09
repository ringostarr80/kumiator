<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Admin-initiiertes Passwort-Reset für einen Benutzer (CLI-Pfad).
 *
 * Aktuell genutzt von `user:reset-password`. Vom Self-Reset-Pfad (über
 * Fortifys `Illuminate\Auth\Events\PasswordReset` →
 * `LogAuthenticationActivityListener`) bewusst getrennt: dort ist der User
 * selbst Causer (`auth/password_reset`), hier wird das Reset als
 * anonymisierter Admin-Vorgang dokumentiert (`auth/password_reset_via_cli`),
 * damit Reports beide Fälle trennen können.
 *
 * Strukturell parallel zu `UserEmailVerifierContract` — Field-Update +
 * dedizierter Audit-Eintrag in einem atomaren Service-Call. Das Klartext-
 * Passwort wird nur entgegengenommen, sofort gehasht und nicht weitergereicht;
 * die Audit-Eintrag enthält das Passwort selbstverständlich nicht.
 */
interface UserPasswordResetterContract
{
    public function reset(User $user, string $newPassword): void;
}
