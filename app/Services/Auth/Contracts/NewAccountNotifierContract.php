<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

use App\Models\User;

/**
 * Was das Postfach eines eben selbst registrierten Kontos bekommt. Das Konto
 * hat noch kein Passwort, das jemand kennt; festlegen kann es nur, wer die
 * Mail liest.
 */
interface NewAccountNotifierContract
{
    public function notify(User $user): void;
}
