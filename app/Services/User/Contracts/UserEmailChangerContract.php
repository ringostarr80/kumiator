<?php

declare(strict_types=1);

namespace App\Services\User\Contracts;

use App\Models\User;

/**
 * Zweistufige Self-Service-E-Mail-Änderung.
 *
 * Ablauf:
 *   1. `requestChange()` — schreibt `pending_email*`, lässt `email` und
 *      `email_verified_at` UNBERÜHRT, versendet Verifizierungs-Mail an die
 *      neue Adresse und Hinweis-Mail an die alte. Audit: `auth/email_change_requested`.
 *      Eine bestehende offene Anfrage wird durch den neuen Token implizit
 *      invalidiert (Spalten-Overwrite).
 *   2. `confirmChange()` — auf Klick auf den Confirm-Link aus der Verifizierungs-
 *      Mail. Tauscht `email` ← `pending_email`, setzt `email_verified_at = now()`,
 *      leert `pending_email*`. Audit: `auth/email_changed` (ohne Properties:
 *      Subject und Causer = User selbst, Datenminimierung Art. 5(1)(c)).
 *   3. `cancelChange()` — auf Klick auf den Cancel-Link aus der Hinweis-Mail
 *      an die alte Adresse. Leert nur `pending_email*`. Audit:
 *      `auth/email_change_cancelled`, causer=anonymous (Hijack-Opfer hat
 *      typischerweise keine Session).
 *
 * Sicherheitsmodell: Der Token IST die Berechtigung — beide Endpoints sind
 * guest-zugänglich. In der DB liegt ausschließlich der SHA-256-Hex-Hash des
 * Klartext-Tokens; Klartext wandert nur durch die URL in der Mail.
 */
interface UserEmailChangerContract
{
    public function requestChange(User $user, string $newEmail): void;

    /**
     * Auditiert einen Änderungsantrag, der an der Re-Authentifizierung
     * gescheitert ist (aktuelles Passwort falsch): `auth/email_change_request_failed`
     * mit `failure_reason = 'current_password_mismatch'`. Forensisch relevant,
     * weil genau dieser Fehlversuch das Signal für eine gekaperte Session ist —
     * ein legitimer Nutzer kennt sein Passwort. Die versuchte Zieladresse wird
     * nur als Hash abgelegt (`pending_email_hash`, Datenminimierung wie bei
     * `requestChange()`). Es findet keine State-Mutation statt.
     */
    public function recordRequestFailed(User $user, ?string $attemptedEmail): void;

    /**
     * @throws \App\Services\User\Exceptions\EmailChangeTokenInvalidException Token unbekannt
     * @throws \App\Services\User\Exceptions\EmailChangeTokenExpiredException Token älter als TTL (60 Min)
     * @throws \App\Services\User\Exceptions\EmailChangeTargetNotEligibleException User ist soft-deleted
     * @throws \App\Services\User\Exceptions\EmailChangeConflictException Adresse zwischenzeitlich Drittem belegt
     */
    public function confirmChange(string $plainToken): User;

    /**
     * Idempotent: unbekannter Token → No-Op (kein Audit, keine Exception).
     */
    public function cancelChange(string $plainToken): void;

    /**
     * Räumt alle Anfragen, deren `pending_email_sent_at` älter als die TTL
     * ist, schreibt pro Eintrag einen `email_change_cancelled`-Audit-Eintrag
     * mit `cancelled_via = 'ttl_expired'` und gibt die Anzahl der bereinigten
     * Datensätze zurück. Aufgerufen vom Scheduled-Cleanup-Command.
     */
    public function cancelExpired(): int;
}
