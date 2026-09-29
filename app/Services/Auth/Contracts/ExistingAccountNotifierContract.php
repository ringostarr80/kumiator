<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Was der Inhaber einer Adresse erfährt, wenn sich jemand mit ihr neu
 * registrieren will. Der Absender bekommt dieselbe Antwort wie bei einer
 * freien Adresse; alles Weitere läuft über das Postfach des Inhabers.
 */
interface ExistingAccountNotifierContract
{
    public function notify(User $user): void;
}
