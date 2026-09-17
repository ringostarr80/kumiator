<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Auth\Contracts\OtherSessionRevokerContract;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Der Ersatz für `SessionGuard::logoutOtherDevices()`, das seine Wirkung aus
 * einem neuen Passwort-Hash zieht und deshalb das Klartextpasswort verlangt.
 * Bei einer Bestätigung per Passkey gibt es keins — und beim Abschalten des
 * Passwort-Logins wäre ein Hash-Wechsel ohnehin die falsche Nebenwirkung.
 */
final class OtherSessionRevoker implements OtherSessionRevokerContract
{
    public function __construct(
        private readonly UserSessionTerminatorContract $sessions,
        private readonly StatefulGuard $guard,
    ) {
    }

    public function revokeFor(User $user): int
    {
        // Ein neuer Token entwertet jeden ausgestellten Recaller-Cookie:
        // `retrieveByToken()` vergleicht ihn stur gegen die Spalte. Er kommt vor
        // der Sitzungslöschung, weil er der Rückweg ist, der weit über die
        // Sitzung hinaus hält und keinen Treiber braucht: Bricht die Löschung ab,
        // ist er schon entwertet. Umgekehrt sähe eine gelöschte Sitzung bei
        // lebendem Cookie aus wie Erfolg und wäre keiner — der Cookie meldete
        // beim nächsten Request neu an.
        $user->setRememberToken(Str::random(60));
        $user->saveOrFail();

        $revoked = $this->sessions->deleteOtherSessionsForUser($user, Session::getId());

        // Das handelnde Gerät hat gerade bestätigt — sein Cookie kommt mit dem
        // neuen Token zurück, sonst wäre es nach Ablauf der Sitzung still
        // abgemeldet. Nicht ohne Passwort-Login: Der Cookie stammt aus einer
        // Passwort-Anmeldung, und die soll es dann nicht mehr geben. Das Gerät
        // bleibt über seine Sitzung angemeldet und meldet sich danach per
        // Passkey neu an.
        if (!$user->isPasswordLoginDisabled()) {
            $this->reissueRecallerCookie($user);
        }

        return $revoked;
    }

    /**
     * Der Guard stellt den Cookie nur im Zuge einer Anmeldung neu aus, und die
     * schriebe ein `Login`-Event ins Protokoll, das es nicht gab — daher der
     * Nachbau aus seinen öffentlichen Teilen. Wer kein „Angemeldet bleiben"
     * gewählt hat, bekommt auch keins.
     *
     * Der Guard kommt benannt aus Fortifys Bindung statt als Default aus
     * `Auth::guard()`: Hinter `auth:sanctum` ist der Default Sanctums
     * `RequestGuard`, und der kennt keinen Recaller — nur der `SessionGuard`
     * stellt einen aus.
     */
    private function reissueRecallerCookie(User $user): void
    {
        $guard = $this->guard;

        if (!$guard instanceof SessionGuard || !Cookie::has($guard->getRecallerName())) {
            return;
        }

        Cookie::queue(Cookie::make(
            $guard->getRecallerName(),
            $user->id . '|' . $user->getRememberToken() . '|' . $guard->hashPasswordForCookie($user->getAuthPassword()),
            Config::integer('auth.guards.web.remember'),
        ));
    }
}
