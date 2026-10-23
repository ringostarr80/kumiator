<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

interface PasswordAttemptLimiterContract
{
    /**
     * Zählt einen Passwortvergleich aus einer angemeldeten Sitzung und meldet, ob
     * er stattfinden darf.
     *
     * Gezählt wird pro Konto, über alle Wege und alle Sitzungen: Getrennte Zähler
     * vervielfachten die Versuche um die Zahl der Wege, und mit einem Recaller-
     * Cookie holt sich ein Angreifer beliebig viele Sitzungen. Ein Zähler für
     * alle sperrte dafür den Eigentümer mit aus. Deshalb endet über der Grenze
     * jede Anmeldung des Kontos samt Recaller-Cookies: Zurück kommt nur, wer das
     * Passwort kennt.
     *
     * Fremde Sitzungen erreicht das nur, wenn sie in der Datenbank liegen. Unter
     * jedem anderen Treiber endet eine erst mit ihrem eigenen Versuch über der
     * Grenze und, bleibt sie still, erst mit ihrer Lebensdauer.
     */
    public function attempt(User $user): bool;

    /**
     * Wer das Passwort nennt, rät nicht: Zählte der Treffer mit, sperrte sich der
     * Eigentümer aus, während er etwa an den Regeln für sein neues Passwort
     * scheitert. Zurückgebucht wird nur dieser eine Versuch, nicht der Zähler
     * zurückgesetzt, denn ein Treffer löschte sonst auch die Fehlversuche einer
     * ratenden Sitzung daneben.
     */
    public function succeeded(User $user): void;
}
