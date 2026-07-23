<?php

declare(strict_types=1);

namespace App\Services\Session\Contracts;

use App\Models\User;

interface UserSessionTerminatorContract
{
    /**
     * Entfernt alle DB-Session-Zeilen des Users. No-Op, solange der
     * Session-Treiber nicht `database` ist (dann existieren keine Zeilen).
     */
    public function deleteForUser(User $user): void;

    /**
     * Zählt die DB-Session-Zeilen des Users ohne die übergebene aktuelle
     * Session. 0, solange der Session-Treiber nicht `database` ist.
     */
    public function countOtherSessionsForUser(int|string $userId, string $currentSessionId): int;

    /**
     * Ob die Session-Persistenz über die Datenbank läuft. Aufrufer, die selbst
     * an den Session-Zeilen hängen (z. B. ein eigener Activity-Log-Eintrag),
     * fragen hier, statt den `session.driver`-Vergleich zu duplizieren.
     */
    public function usesDatabaseDriver(): bool;
}
