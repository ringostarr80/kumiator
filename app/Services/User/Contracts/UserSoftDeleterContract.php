<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Administrativer Soft-Delete-Pfad für einen Benutzer.
 *
 * Im Gegensatz zum Hard-Delete bleibt die fachliche Historie
 * — insbesondere die Activity-Log-Verweise auf den Benutzer — erhalten;
 * gelöscht oder ersetzt werden ausschließlich Zugriffsmittel (Passkeys, ein
 * offener Link zum Zurücksetzen oder zum E-Mail-Wechsel, das Passwort), damit
 * ein späteres `restore()` keinen Wieder-Zugang über Alt-Cookies, Passkeys oder
 * diese Links eröffnet. Am Passwort hängen auch alte Sessions und Cookies hinter
 * „Angemeldet bleiben“, bei jedem Session-Treiber; die Session-Zeilen in der
 * Datenbank entfernt das Löschen zusätzlich.
 * Mit den Passkeys fällt auch ein abgeschalteter Passwort-Login —
 * sonst stünde das wiederhergestellte Konto ohne jeden Anmeldeweg da.
 *
 * Passkey-Lifecycle-Events laufen über das `LogsActivity`-Trait des
 * `PasskeyCredential`-Models und brauchen hier nichts Eigenes.
 */
interface UserSoftDeleterContract
{
    public function softDelete(User $user): void;

    /**
     * Ein Request, der sich vor dem Löschen angemeldet hat, kann danach noch
     * schreiben, etwa einen Passkey oder ein Passwort. Das gelöschte Konto
     * betritt niemand, beim Restore steht also alles da, was solche Nachzügler
     * hinterlassen haben. Deshalb räumt der Restore zuerst dasselbe ab wie das
     * Löschen.
     *
     * Nur für gelöschte Konten: Einem aktiven nähme die Abräumung Passwort und
     * Passkeys.
     */
    public function restore(User $user): void;
}
