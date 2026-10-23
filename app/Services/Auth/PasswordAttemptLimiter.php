<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Models\User;
use App\Services\Auth\Contracts\PasswordAttemptLimiterContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\Activity;

/**
 * Jeder Versuch über der Grenze beendet die Anmeldungen erneut, nicht nur der
 * erste: Fremde Sitzungen erreicht das Löschen nur unter dem Treiber `database`,
 * unter jedem anderen endet eine Sitzung erst mit ihrem eigenen Versuch.
 */
final class PasswordAttemptLimiter implements PasswordAttemptLimiterContract
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(
        private readonly UserSessionTerminatorContract $sessions,
        private readonly StatefulGuard $guard,
    ) {
    }

    /**
     * Hochzählen und Prüfen in einem Schritt: Getrennt läsen gleichzeitige
     * Versuche alle denselben Stand und kämen alle durch.
     */
    public function attempt(User $user): bool
    {
        if (RateLimiter::hit($this->key($user), self::DECAY_SECONDS) <= self::MAX_ATTEMPTS) {
            return true;
        }

        $this->endEverySession($user);

        return false;
    }

    public function succeeded(User $user): void
    {
        RateLimiter::decrement($this->key($user), self::DECAY_SECONDS);
    }

    private function key(User $user): string
    {
        return 'password-attempt:' . $user->id;
    }

    private function endEverySession(User $user): void
    {
        // Zuerst der Token: Er hält weit über jede Sitzung hinaus und braucht
        // keinen Treiber. Ohne Zeitstempel: Der Token ist keine Bearbeitung des
        // Kontos, auch Laravels eigene Rotation beim Logout lässt `updated_at`
        // stehen.
        $user->setRememberToken(Str::random(60));
        User::withoutTimestamps(static fn () => $user->saveOrFail());

        $this->sessions->deleteForUser($user);
        Session::invalidate();

        // Entwertet ist der Cookie mit dem Token schon, der Browser schickte ihn
        // sonst aber weiter mit. Der Guard kommt aus Fortifys Bindung, denn hinter
        // `auth:sanctum` ist der Default Sanctums `RequestGuard`, und der kennt
        // keinen Recaller.
        if ($this->guard instanceof SessionGuard) {
            Cookie::queue(Cookie::forget($this->guard->getRecallerName()));
        }

        // Zuletzt: Ein fehlschlagender Eintrag darf keine Sitzung am Leben halten.
        Activity::useLog(ActivityChannel::AUTH->value)
            ->event(ActivityEvent::PASSWORD_ATTEMPTS_LOCKED_OUT->value)
            ->causedBy($user)
            ->performedOn($user)
            ->log('');
    }
}
