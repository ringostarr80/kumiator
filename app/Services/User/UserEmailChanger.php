<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\VerifyEmailChangeNotification;
use App\Services\Audit\AuditEmailHasher;
use App\Services\User\Contracts\UserEmailChangerContract;
use App\Services\User\Exceptions\EmailChangeConflictException;
use App\Services\User\Exceptions\EmailChangeTargetNotEligibleException;
use App\Services\User\Exceptions\EmailChangeTokenExpiredException;
use App\Services\User\Exceptions\EmailChangeTokenInvalidException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Facades\Activity;

/**
 * Konkretes Verfahren der zweistufigen E-Mail-Änderung. Siehe Contract für
 * den Lebenszyklus und die Begründung der Sicherheits-Entscheidungen.
 *
 * Klartext-Tokens: je Aktion ein eigener `bin2hex(random_bytes(32))`-Token
 * (64 Hex-Zeichen) — der Confirm-Token geht in die Mail an die NEUE, der
 * Cancel-Token in die Mail an die ALTE Adresse. Getrennt, damit die
 * Cancel-Mail niemanden zum Bestätigen befähigt (siehe Contract).
 * Persistiert wird ausschließlich `hash('sha256', $plainToken)` je Spalte;
 * der Klartext wandert nur durch die URL in der Mail. Bei Confirm/Cancel
 * wird der eingehende URL-Parameter erneut gehasht und nur gegen die Spalte
 * der jeweiligen Aktion verglichen — `hash_equals` ist hier nicht zwingend
 * notwendig (Hash-Lookup über `where(...)->first()` verrät keine
 * Teil-Treffer), aber auch nicht schädlich; wir bleiben beim einfachen
 * Equality-Lookup.
 */
final class UserEmailChanger implements UserEmailChangerContract
{
    private const TOKEN_TTL_MINUTES = 60;

    public function requestChange(User $user, string $newEmail): void
    {
        $plainConfirmToken = bin2hex(random_bytes(32));
        $plainCancelToken = bin2hex(random_bytes(32));

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_confirm_token_hash' => hash('sha256', $plainConfirmToken),
            'pending_email_cancel_token_hash' => hash('sha256', $plainCancelToken),
            'pending_email_sent_at' => Carbon::now(),
        ])->saveOrFail();

        Notification::route('mail', $newEmail)
            ->notify(new VerifyEmailChangeNotification($user, $plainConfirmToken, $newEmail));

        $user->notify(new EmailChangeRequestedNotification($plainCancelToken, $newEmail));

        // DSGVO Art. 5(1)(c): `pending_email` kann eine fremde Adresse sein
        // (Tippfehler des Antragstellers, oder ein Angreifer auf einem
        // übernommenen Konto, der gegen eine Wegwerf-Adresse wechselt).
        // Klartext-Speicherung über 365 Tage wäre Datenverarbeitung Dritter
        // ohne Rechtsgrundlage. Hash erlaubt Korrelation (wiederholte
        // Versuche an dieselbe Zieladresse), ohne den Klartext zu halten —
        // dasselbe Muster wie `login_failed` (siehe `AuditEmailHasher`).
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_CHANGE_REQUESTED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['pending_email_hash' => AuditEmailHasher::hash($newEmail)])
            ->log(ActivityEvent::EMAIL_CHANGE_REQUESTED->description());
    }

    public function recordRequestFailed(User $user, ?string $attemptedEmail): void
    {
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_CHANGE_REQUEST_FAILED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'failure_reason' => 'current_password_mismatch',
                'pending_email_hash' => AuditEmailHasher::hash($attemptedEmail),
            ])
            ->log(ActivityEvent::EMAIL_CHANGE_REQUEST_FAILED->description());
    }

    public function confirmChange(string $plainToken): User
    {
        $user = $this->resolveUserByToken($plainToken, 'pending_email_confirm_token_hash');

        if ($user === null) {
            $this->recordCancelTokenOnConfirm($plainToken);

            throw new EmailChangeTokenInvalidException();
        }

        if ($this->isExpired($user)) {
            // Konsolidiert auf `cancelChangeForUser()`: erzeugt einen
            // `email_change_cancelled`-Eintrag mit `cancelled_via='expired_on_confirm'`
            // (parallel zu `ttl_expired` aus dem cron-Pfad `cancelExpired()`).
            // Damit ist die State-Mutation `clearPendingFields()` durchgängig
            // auditiert — vorher gab es eine stille Mutation ohne Log.
            $this->cancelChangeForUser($user, 'expired_on_confirm');
            throw new EmailChangeTokenExpiredException();
        }

        if ($user->trashed()) {
            // Keine State-Mutation, aber forensisch relevant: jemand mit
            // Token-Zugriff versucht, einen gelöschten Account zu reaktivieren.
            // Eigener Event-Code (kein `cancelled`-Eintrag), weil nichts
            // abgebrochen wird — der Pending-State bleibt unverändert, der
            // Account ist nur nicht mehr eligible.
            Activity::useLog(ActivityChannel::AUTH->value)
                ->event(ActivityEvent::EMAIL_CHANGE_CONFIRMATION_REJECTED->value)
                ->causedByAnonymous()
                ->performedOn($user)
                ->withProperties(['reason' => 'target_not_eligible'])
                ->log(ActivityEvent::EMAIL_CHANGE_CONFIRMATION_REJECTED->description());

            throw new EmailChangeTargetNotEligibleException();
        }

        $pendingEmail = (string)$user->pending_email;

        if (User::query()->where('email', $pendingEmail)->whereKeyNot($user->getKey())->exists()) {
            // Analog zum expired-Pfad: State-Mutation via `cancelChangeForUser()`
            // mit `cancelled_via='target_taken_on_confirm'`. Dokumentiert sowohl
            // den Confirm-Fehlversuch als auch die durchgeführte Bereinigung
            // der `pending_email*`-Felder in einem einzigen Eintrag.
            $this->cancelChangeForUser($user, 'target_taken_on_confirm');
            throw new EmailChangeConflictException();
        }

        $user->forceFill([
            'email' => $pendingEmail,
            'email_verified_at' => Carbon::now(),
            'pending_email' => null,
            'pending_email_confirm_token_hash' => null,
            'pending_email_cancel_token_hash' => null,
            'pending_email_sent_at' => null,
        ])->saveOrFail();

        // Bewusst keine Properties: `subject` und `causer` zeigen beide auf
        // den User selbst, der Vorgang ist damit eindeutig zuordenbar.
        // Die alte Adresse 365 Tage zusätzlich vorzuhalten geht über
        // Art. 5(1)(c) Datenminimierung hinaus.
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_CHANGED->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log(ActivityEvent::EMAIL_CHANGED->description());

        return $user;
    }

    public function cancelChange(string $plainToken): void
    {
        $user = $this->resolveUserByToken($plainToken, 'pending_email_cancel_token_hash');

        if ($user === null) {
            return;
        }

        $this->cancelChangeForUser($user, 'recipient_revoked');
    }

    public function cancelExpired(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::TOKEN_TTL_MINUTES);

        $expired = User::query()
            ->withTrashed()
            ->whereNotNull('pending_email_confirm_token_hash')
            ->where('pending_email_sent_at', '<', $cutoff)
            ->get();

        foreach ($expired as $user) {
            $this->cancelChangeForUser($user, 'ttl_expired');
        }

        return $expired->count();
    }

    private function cancelChangeForUser(User $user, string $cancelledVia): void
    {
        $pendingEmail = (string)$user->pending_email;

        $this->clearPendingFields($user);

        // Causer ist anonym; `pending_email` würde sonst eine ggf. fremde
        // Adresse (siehe `requestChange()`-Kommentar) 365 Tage halten.
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_CHANGE_CANCELLED->value)
            ->causedByAnonymous()
            ->performedOn($user)
            ->withProperties([
                'pending_email_hash' => AuditEmailHasher::hash($pendingEmail),
                'cancelled_via' => $cancelledVia,
            ])
            ->log(ActivityEvent::EMAIL_CHANGE_CANCELLED->description());
    }

    /**
     * Trifft ein am Confirm-Endpoint eingereichter Token die CANCEL-Spalte,
     * versucht jemand mit Einsicht in die Hinweis-Mail der alten Adresse zu
     * bestätigen statt abzubrechen — exakt das Angriffsmuster, gegen das die
     * Token-Trennung schützt, und damit ein starkes Forensik-Signal. Keine
     * State-Mutation; der Aufrufer antwortet weiterhin mit dem
     * undifferenzierten Invalid-View, damit der Audit-Eintrag kein
     * Token-Oracle über die Response erzeugt.
     *
     * Komplett unbekannte Tokens (auch kein Treffer in der Cancel-Spalte)
     * bleiben bewusst ohne Audit-Eintrag: Ohne Causer, performedOn oder
     * korrelierbare Property (anders als `login_failed` mit E-Mail-Hash)
     * wäre der Eintrag forensisch wertlos und würde durch Bot-Scans nur
     * Rauschen erzeugen. Brute-Force-Schutz gehört auf Rate-Limit-Ebene,
     * nicht ins Activity-Log.
     */
    private function recordCancelTokenOnConfirm(string $plainToken): void
    {
        $user = $this->resolveUserByToken($plainToken, 'pending_email_cancel_token_hash');

        if ($user === null) {
            return;
        }

        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::EMAIL_CHANGE_CONFIRMATION_REJECTED->value)
            ->causedByAnonymous()
            ->performedOn($user)
            ->withProperties(['reason' => 'cancel_token_on_confirm'])
            ->log(ActivityEvent::EMAIL_CHANGE_CONFIRMATION_REJECTED->description());
    }

    private function resolveUserByToken(string $plainToken, string $tokenHashColumn): ?User
    {
        $hash = hash('sha256', $plainToken);

        return User::query()
            ->withTrashed()
            ->where($tokenHashColumn, $hash)
            ->first();
    }

    private function isExpired(User $user): bool
    {
        $sentAt = $user->pending_email_sent_at;

        if ($sentAt === null) {
            return true;
        }

        return $sentAt->lt(Carbon::now()->subMinutes(self::TOKEN_TTL_MINUTES));
    }

    private function clearPendingFields(User $user): void
    {
        $user->forceFill([
            'pending_email' => null,
            'pending_email_confirm_token_hash' => null,
            'pending_email_cancel_token_hash' => null,
            'pending_email_sent_at' => null,
        ])->saveOrFail();
    }
}
