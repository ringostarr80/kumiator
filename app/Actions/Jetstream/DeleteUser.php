<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\User\Contracts\UserHardDeleterContract;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    public function __construct(private readonly UserHardDeleterContract $hardDeleter)
    {
    }

    /**
     * Delete the given user.
     *
     * Self-Delete ist bewusst ein Hard-Delete: DSGVO-konformes „Recht auf Vergessen".
     * Die eigentliche Mechanik (Tokens, Passkeys, Sessions, Rollen-Pivots, Activity-
     * Log-Purge, anonymisierter Audit-Eintrag) liegt im `UserHardDeleter`-Service,
     * den auch der administrative `user:force-delete`-Command nutzt. Hier wird der
     * Self-Delete-spezifische Event übergeben, sodass der zurückbleibende
     * Audit-Eintrag den Vorgang fachlich von einem Admin-Force-Delete unterscheidet.
     */
    public function delete(User $user): void
    {
        $this->hardDeleter->forceDelete($user, ActivityEvent::ACCOUNT_SELF_DELETED);
    }
}
