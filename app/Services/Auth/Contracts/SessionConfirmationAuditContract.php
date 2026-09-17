<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Enums\ActivityFailureReason;
use App\Models\User;

interface SessionConfirmationAuditContract
{
    public function recordPasskeySuccess(User $user): void;

    /**
     * @param ?ActivityFailureReason $reason Nur gesetzt, wo der Fehlschlag eine andere
     *        Reaktion verlangt als ein weiterer Versuch.
     * @param ?string $detail Wortlaut der WebAuthn-Bibliothek oder ein fester Code aus einer
     *        eigenen Prüfung; nie übersetzt, damit die Einträge vergleichbar bleiben. Der Browser
     *        sieht ihn nicht.
     */
    public function recordPasskeyFailure(
        User $user,
        ?ActivityFailureReason $reason = null,
        ?string $detail = null,
    ): void;

    public function recordPasswordFailure(User $user, ActivityFailureReason $reason): void;
}
