<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\CompleteRegistrationNotification;
use App\Services\Auth\Contracts\NewAccountNotifierContract;

final class NewAccountNotifier implements NewAccountNotifierContract
{
    public function notify(User $user): void
    {
        $user->notify(new CompleteRegistrationNotification());
    }
}
