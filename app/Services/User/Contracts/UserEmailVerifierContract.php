<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Admin-initiierte E-Mail-Verifizierung für einen Benutzer.
 *
 * Aktuell genutzt vom CLI-Pfad (`user:verify`); ein zukünftiges Admin-UI
 * (Web-Backoffice) kann denselben Vertrag nutzen, ohne den Audit-Pfad zu
 * duplizieren. Die Trennung zum Self-Verify (über `VerifyEmailController` →
 * `Illuminate\Auth\Events\Verified` → `LogAuthenticationActivityListener`)
 * ist absichtlich: dort ist der User selbst Causer (`auth/email_verified`),
 * hier wird das Verify als anonymisierter Admin-Vorgang dokumentiert
 * (`auth/email_verified_via_cli`), damit Reports beide Fälle trennen können.
 *
 * Idempotenz-Hinweis: Der Aufrufer prüft `hasVerifiedEmail()` und ruft
 * `verify()` nur, wenn der User noch nicht verifiziert ist — dieser Service
 * verifiziert bedingungslos und schreibt einen Audit-Eintrag.
 */
interface UserEmailVerifierContract
{
    public function verify(User $user): void;
}
