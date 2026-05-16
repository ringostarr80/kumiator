<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\VerifyEmailChangeNotification;
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
 * Klartext-Token: `bin2hex(random_bytes(32))` → 64 Hex-Zeichen. Persistiert
 * wird ausschließlich `hash('sha256', $plainToken)` (ebenfalls 64 Hex). Der
 * Klartext wandert nur durch die URL in der Mail. Bei Confirm/Cancel wird
 * der eingehende URL-Parameter erneut gehasht und gegen `pending_email_token_hash`
 * verglichen — `hash_equals` ist hier nicht zwingend notwendig (Hash-Lookup
 * über `where(...)->first()` verrät keine Teil-Treffer), aber auch nicht
 * schädlich; wir bleiben beim einfachen Equality-Lookup.
 */
final class UserEmailChanger implements UserEmailChangerContract
{
    private const TOKEN_TTL_MINUTES = 60;

    public function requestChange(User $user, string $newEmail): void
    {
        $plainToken = bin2hex(random_bytes(32));

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_token_hash' => hash('sha256', $plainToken),
            'pending_email_sent_at' => Carbon::now(),
        ])->saveOrFail();

        Notification::route('mail', $newEmail)
            ->notify(new VerifyEmailChangeNotification($user, $plainToken, $newEmail));

        $user->notify(new EmailChangeRequestedNotification($plainToken, $newEmail));

        Activity::useLog('auth')
            ->event('email_change_requested')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['pending_email' => $newEmail])
            ->log(__('app.activity_email_change_requested'));
    }

    public function confirmChange(string $plainToken): User
    {
        $user = $this->resolveUserByToken($plainToken);

        if ($user === null) {
            // Bewusst kein Audit-Eintrag: Ohne Causer, performedOn oder
            // korrelierbare Property (anders als `login_failed` mit E-Mail-
            // Hash) wäre der Eintrag forensisch wertlos und würde durch
            // Bot-Scans nur Rauschen erzeugen. Brute-Force-Schutz gehört
            // auf Rate-Limit-Ebene, nicht ins Activity-Log.
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
            Activity::useLog('auth')
                ->event('email_change_confirmation_rejected')
                ->causedByAnonymous()
                ->performedOn($user)
                ->withProperties(['reason' => 'target_not_eligible'])
                ->log(__('app.activity_email_change_confirmation_rejected'));

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

        $oldEmail = $user->email;

        $user->forceFill([
            'email' => $pendingEmail,
            'email_verified_at' => Carbon::now(),
            'pending_email' => null,
            'pending_email_token_hash' => null,
            'pending_email_sent_at' => null,
        ])->saveOrFail();

        Activity::useLog('auth')
            ->event('email_changed')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['old_email' => $oldEmail])
            ->log(__('app.activity_email_changed'));

        return $user;
    }

    public function cancelChange(string $plainToken): void
    {
        $user = $this->resolveUserByToken($plainToken);

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
            ->whereNotNull('pending_email_token_hash')
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

        Activity::useLog('auth')
            ->event('email_change_cancelled')
            ->causedByAnonymous()
            ->performedOn($user)
            ->withProperties([
                'pending_email' => $pendingEmail,
                'cancelled_via' => $cancelledVia,
            ])
            ->log(__('app.activity_email_change_cancelled'));
    }

    private function resolveUserByToken(string $plainToken): ?User
    {
        $hash = hash('sha256', $plainToken);

        return User::query()
            ->withTrashed()
            ->where('pending_email_token_hash', $hash)
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
            'pending_email_token_hash' => null,
            'pending_email_sent_at' => null,
        ])->saveOrFail();
    }
}
