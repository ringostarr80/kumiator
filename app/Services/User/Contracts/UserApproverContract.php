<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Schaltet einen Benutzer frei (setzt `approved_at`).
 *
 * Aktuell genutzt vom CLI-Pfad (`user:approve`); ein künftiges Admin-UI kann
 * denselben Vertrag nutzen, ohne den Audit-Pfad zu duplizieren.
 *
 * Audit (bewusst implizit): Anders als der `UserEmailVerifierContract` schreibt
 * dieser Service KEINEN expliziten Activity-Log-Eintrag. `approved_at` steht in
 * der `logOnly`-Liste des Activity-Log-Setups am `User`-Model, weshalb der
 * `saveOrFail()` bereits einen Eintrag erzeugt; der `Activity::saving`-Hook
 * mappt das generische `updated`-Event auf den fachlichen Code `user_approved`.
 * Im CLI-Pfad hängt der `ConsoleActorContext` zusätzlich `cli_actor` an und
 * nullt den Causer.
 *
 * Idempotenz-Hinweis: Der Aufrufer prüft `approved_at` und ruft `approve()` nur
 * für noch nicht freigeschaltete User — dieser Service schaltet bedingungslos frei.
 */
interface UserApproverContract
{
    public function approve(User $user): void;
}
