<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager as JetstreamApiTokenManager;
use Spatie\Activitylog\Facades\Activity;

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

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::API_TOKEN_CREATED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'token_id' => $accessToken->getKey(),
                'token_name' => $accessToken->getAttribute('name'),
                'abilities' => $accessToken->getAttribute('abilities'),
            ])
            ->log(ActivityEvent::API_TOKEN_CREATED->description());
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

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::API_TOKEN_REVOKED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'token_id' => $token->getKey(),
                'token_name' => $token->getAttribute('name'),
                'abilities' => $token->getAttribute('abilities'),
            ])
            ->log(ActivityEvent::API_TOKEN_REVOKED->description());
    }
}
