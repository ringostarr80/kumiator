<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Admin-initiierte E-Mail-Verifizierung für einen Benutzer.
 *
 * Aktuell genutzt vom CLI-Pfad (`user:verify`); ein zukünftiges Admin-UI
 * (Web-Backoffice) kann denselben Vertrag nutzen, ohne den Audit-Pfad zu
 * duplizieren. Schreibt denselben Event-Code (`auth/email_verified`) wie
 * der Self-Verify-Pfad (über `VerifyEmailController`/`SelfEmailVerifier`
 * → `Illuminate\Auth\Events\Verified` → `LogAuthenticationActivityListener`);
 * die Unterscheidung „Self-Verify vs. Admin-CLI-Verify" steckt im Causer:
 * Self-Pfad → User als Causer, CLI-Pfad → `causedByAnonymous()` plus
 * `cli_actor`-Property aus `ConsoleActorContext`. Reports trennen die
 * Fälle damit über `causer_id IS NULL` bzw. `properties->>'cli_actor'
 * IS NOT NULL`.
 *
 * Idempotenz-Hinweis: Der Aufrufer prüft `hasVerifiedEmail()` und ruft
 * `verify()` nur, wenn der User noch nicht verifiziert ist — dieser Service
 * verifiziert bedingungslos und schreibt einen Audit-Eintrag.
 */
interface UserEmailVerifierContract
{
    public function verify(User $user): void;
}
