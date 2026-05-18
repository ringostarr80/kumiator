<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Admin-initiiertes Passwort-Reset für einen Benutzer (CLI-Pfad).
 *
 * Aktuell genutzt von `user:reset-password`. Schreibt denselben Event-Code
 * (`auth/password_reset`) wie der Self-Reset-Pfad (über Fortifys
 * `Illuminate\Auth\Events\PasswordReset` →
 * `LogAuthenticationActivityListener`); die Unterscheidung „Self-Service
 * vs. Admin-CLI" steckt im Causer: Self-Pfad → User als Causer, CLI-Pfad
 * → `causedByAnonymous()` plus `cli_actor`-Property aus
 * `ConsoleActorContext`. Reports trennen die Fälle damit über
 * `causer_id IS NULL` bzw. `properties->>'cli_actor' IS NOT NULL`.
 *
 * Strukturell parallel zu `UserEmailVerifierContract` — Field-Update +
 * Audit-Eintrag in einem atomaren Service-Call. Das Klartext-Passwort
 * wird nur entgegengenommen, sofort gehasht und nicht weitergereicht;
 * die Audit-Eintrag enthält das Passwort selbstverständlich nicht.
 */
interface UserPasswordResetterContract
{
    public function reset(User $user, string $newPassword): void;
}
