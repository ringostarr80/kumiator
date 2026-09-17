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
     * Entfernt die DB-Session-Zeilen des Users bis auf die übergebene aktuelle
     * und meldet, wie viele es waren. 0, solange der Session-Treiber nicht
     * `database` ist — dann liegen die Sitzungen ausserhalb der Datenbank und
     * bleiben unberührt.
     */
    public function deleteOtherSessionsForUser(User $user, string $currentSessionId): int;

    /**
     * Ob die Session-Persistenz über die Datenbank läuft. Aufrufer, die selbst
     * an den Session-Zeilen hängen (z. B. ein eigener Activity-Log-Eintrag),
     * fragen hier, statt den `session.driver`-Vergleich zu duplizieren.
     */
    public function usesDatabaseDriver(): bool;
}
