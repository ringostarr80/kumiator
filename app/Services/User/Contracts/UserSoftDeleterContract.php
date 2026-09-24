<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Administrativer Soft-Delete-Pfad für einen Benutzer.
 *
 * Im Gegensatz zum Hard-Delete bleibt die fachliche Historie
 * — insbesondere die Activity-Log-Verweise auf den Benutzer — erhalten;
 * gelöscht werden ausschließlich aktive Zugriffsmittel (Sessions, Passkeys,
 * ein offener Link zum Zurücksetzen), damit ein späteres `restore()` keinen
 * Wieder-Zugang über Alt-Cookies, Passkeys oder den Link eröffnet.
 * Mit den Passkeys fällt auch ein abgeschalteter Passwort-Login —
 * sonst stünde das wiederhergestellte Konto ohne jeden Anmeldeweg da — und mit
 * ihm das Passwort, dem die Abschaltung gerade das Vertrauen entzogen hatte.
 *
 * Passkey-Lifecycle-Events laufen über das `LogsActivity`-Trait des
 * `PasskeyCredential`-Models und brauchen hier nichts Eigenes.
 */
interface UserSoftDeleterContract
{
    public function softDelete(User $user): void;
}
