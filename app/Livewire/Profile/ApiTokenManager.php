<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use App\Services\Audit\Contracts\SanctumTokenAuditorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager as JetstreamApiTokenManager;

/**
 * Erweiterung der Jetstream-Komponente um Activity-Log-Erfassung.
 *
 * Sanctum feuert keine Framework-Events bei Token-Erstellung oder -Widerruf.
 * API-Tokens sind aber vollwertige Anmeldeinformationen — DSGVO Art. 32
 * verlangt Nachvollziehbarkeit dieser sicherheitsrelevanten Vorgänge. Wir
 * erweitern die Standard-Komponente und schreiben:
 *  - `api_token_created` nach erfolgreichem Persistieren — der Parent feuert
 *    `Validator::make(...)->validateWithBag()`, das bei Validation-Fehlern
 *    eine Exception wirft. Erreichen wir den Log-Code, ist der Token
 *    persistiert.
 *  - `api_token_revoked` nach erfolgreichem Delete, wobei wir Token-Name
 *    und Abilities VOR dem Parent-Aufruf snapshotten (danach wäre der
 *    Datensatz weg).
 *
 * KEIN Klartext-Token in den Properties — der wird in `$plainTextToken`
 * einmalig im UI angezeigt und ist danach nicht mehr rekonstruierbar
 * (Datenminimierung, DSGVO Art. 5 Abs. 1 lit. c).
 *
 * Token-Auflösung beim Create: Statt direkt auf das Sanctum-`NewAccessToken`
 * zuzugreifen (würde diese Komponente an `Laravel\Sanctum\*` koppeln, was
 * die Architektur-Regel für Livewire bewusst ausschließt), holen wir den
 * gerade erzeugten Datensatz nach dem Parent-Aufruf via
 * `tokens()->latest('id')->first()`. Livewire-Requests sind pro User-Session
 * serialisiert; die theoretische Race-Condition zweier paralleler Creates
 * desselben Users ist praktisch ausgeschlossen.
 */
final class ApiTokenManager extends JetstreamApiTokenManager
{
    private SanctumTokenAuditorContract $tokenAuditor;

    /**
     * Livewires `boot()` ist Container-aware und löst Type-Hints per DI auf —
     * die Aktions-Methoden können den Auditor nicht selbst entgegennehmen, ohne
     * die parameterlose Parent-Signatur zu brechen (LSP).
     */
    public function boot(SanctumTokenAuditorContract $tokenAuditor): void
    {
        $this->tokenAuditor = $tokenAuditor;
    }

    public function createApiToken(): void
    {
        parent::createApiToken();

        $user = Auth::user();

        if (!$user instanceof User) {
            return;
        }

        $accessToken = $user->tokens()->latest('id')->first();

        if (!$accessToken instanceof Model) {
            return;
        }

        $this->tokenAuditor->recordCreated($user, $accessToken);
    }

    public function deleteApiToken(): void
    {
        $user = Auth::user();

        // Token-Snapshot vor dem Parent-Aufruf — danach ist der Datensatz weg
        // und wir hätten weder Name noch Abilities für den Audit-Eintrag.
        $token = $user instanceof User
            ? $user->tokens()->where('id', $this->apiTokenIdBeingDeleted)->first()
            : null;

        parent::deleteApiToken();

        if (!$user instanceof User || !$token instanceof Model) {
            return;
        }

        $this->tokenAuditor->recordRevoked($user, $token);
    }
}
