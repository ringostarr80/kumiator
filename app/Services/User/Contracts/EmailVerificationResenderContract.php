<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Erneutes Anfordern der E-Mail-Verifizierungs-Mail durch den eingeloggten,
 * noch nicht verifizierten User (Self-Service-Resend).
 *
 * Versendet die Notification und schreibt das forensische Gegenstück zum
 * abschließenden `auth/email_verified` als `auth/email_verification_requested`
 * — symmetrisch zu `password_reset_requested` für den Reset-Link. Der Vorgang
 * hat KEIN Framework-Event (Fortifys `EmailVerificationNotificationController`
 * ruft `sendEmailVerificationNotification()` direkt auf), deshalb liegt
 * Versand + Audit hier im Service statt in einem Listener.
 *
 * Idempotenz-Hinweis: Der Aufrufer prüft `hasVerifiedEmail()` und ruft
 * `resend()` nur für noch nicht verifizierte User — dieser Service versendet
 * bedingungslos und loggt.
 */
interface EmailVerificationResenderContract
{
    public function resend(User $user): void;
}
