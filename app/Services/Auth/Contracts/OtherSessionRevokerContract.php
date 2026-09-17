<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

interface OtherSessionRevokerContract
{
    /**
     * Beendet jede Anmeldung des Kontos ausser der laufenden und meldet, wie
     * viele Sitzungen das waren.
     *
     * Zwei Wege führen zurück ins Konto, und beide müssen zugleich enden: die
     * gespeicherte Sitzung und der Recaller-Cookie. Bliebe der Cookie gültig,
     * meldete er nach dem Wegfall der Sitzung sofort wieder an — ohne dass je
     * ein Passwort verglichen würde. Nur das handelnde Gerät behält seinen,
     * solange der Passwort-Login an ist.
     *
     * An die Sitzungen kommt der Widerruf nur heran, wenn sie in der Datenbank
     * liegen. Unter jedem anderen Treiber bleibt die Entwertung des Cookies,
     * und gemeldet werden 0 Sitzungen.
     */
    public function revokeFor(User $user): int;
}
