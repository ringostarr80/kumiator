<?php

declare(strict_types=1);

namespace App\Services\Audit\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit-Einträge für Sanctum-Personal-Access-Tokens.
 *
 * Sanctums `PersonalAccessToken` trägt kein `LogsActivity`-Trait und feuert
 * keine Framework-Events — Erstellung und Widerruf müssen explizit
 * protokolliert werden (DSGVO Art. 32: API-Tokens sind Anmeldeinformationen).
 * Dieser eine Ort hält Property-Schema (`token_id`/`token_name`/`abilities`)
 * und Event-Form, damit der UI-Pfad (`ApiTokenManager`) und der CLI-Lösch-Pfad
 * (`UserSoftDeleter`) nicht auseinanderlaufen.
 *
 * Das Token ist als `Model` typisiert, weil der UI-Pfad bewusst nicht an
 * `Laravel\Sanctum\*` koppelt; die Attribute werden über `getAttribute()`
 * gelesen.
 */
interface SanctumTokenAuditorContract
{
    public function recordCreated(User $subject, Model $token): void;

    /**
     * Ability-Änderung an einem bestehenden Token (Rechte-Eskalation). Die
     * bisherigen Abilities landen als `previous_abilities` neben den neuen, damit
     * der Delta im Audit sichtbar bleibt — der `token`-Snapshot trägt sonst nur
     * den Nachher-Zustand.
     *
     * @param list<string> $previousAbilities
     */
    public function recordPermissionsChanged(User $subject, Model $token, array $previousAbilities): void;

    /**
     * Widerruf durch den Eigentümer selbst (UI): Causer ist `$subject`.
     */
    public function recordRevoked(User $subject, Model $token): void;

    /**
     * Administrativer Widerruf ohne authentifizierten Causer (CLI-Lösch-Pfad):
     * Causer anonym; der handelnde Admin steckt im `cli_actor`-Property.
     */
    public function recordRevokedAnonymously(User $subject, Model $token): void;
}
