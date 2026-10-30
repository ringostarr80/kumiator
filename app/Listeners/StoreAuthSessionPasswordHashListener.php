<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * Der Login hinterlegt den Passwort-Hash unter dem Namen des Session-Guards,
 * `AuthenticateSession` prüft hinter `auth:sanctum` aber den von Jetstreams
 * Guard. Fehlt der, übernimmt die Middleware den Hash, den das Konto beim ersten
 * geschützten Aufruf gerade hat. Eine Session, die bis dahin keine geschützte
 * Seite aufgerufen hat, überstünde so jeden Wechsel des Passworts, auch den
 * beim Soft-Delete.
 */
final class StoreAuthSessionPasswordHashListener
{
    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (!$guard instanceof SessionGuard) {
            return;
        }

        $guard->getSession()->put(
            'password_hash_' . Config::string('jetstream.guard'),
            $guard->hashPasswordForCookie($event->user->getAuthPassword()),
        );
    }
}
