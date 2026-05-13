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
            throw new EmailChangeTokenInvalidException();
        }

        if ($this->isExpired($user)) {
            $this->clearPendingFields($user);
            throw new EmailChangeTokenExpiredException();
        }

        if ($user->trashed()) {
            throw new EmailChangeTargetNotEligibleException();
        }

        $pendingEmail = (string)$user->pending_email;

        if (User::query()->where('email', $pendingEmail)->whereKeyNot($user->getKey())->exists()) {
            $this->clearPendingFields($user);
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
