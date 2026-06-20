<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Administrativer Soft-Delete-Pfad für einen Benutzer.
 *
 * Im Gegensatz zum Hard-Delete bleibt die fachliche Historie
 * — insbesondere die Activity-Log-Verweise auf den Benutzer — erhalten;
 * gelöscht werden ausschließlich aktive Zugriffsmittel (Sessions, Sanctum-
 * Tokens, Passkeys), damit ein späteres `restore()` keinen Wieder-Zugang
 * über Alt-Cookies/Tokens/Passkeys eröffnet.
 *
 * Der Service ist zuständig für die Audit-Symmetrie zum UI-Pfad: jedes
 * widerrufene Token erhält denselben `api_token_revoked`-Eintrag, den auch
 * `ApiTokenManager::deleteApiToken()` schreibt — sonst verschwänden die
 * Tokens stumm, weil Sanctums `PersonalAccessToken` kein `LogsActivity`-
 * Trait nutzt. Passkey-Lifecycle-Events laufen über das `LogsActivity`-
 * Trait des `PasskeyCredential`-Models und brauchen hier nichts Eigenes.
 */
interface UserSoftDeleterContract
{
    public function softDelete(User $user): void;
}
