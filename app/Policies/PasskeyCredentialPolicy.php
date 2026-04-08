<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PasskeyCredential;
use App\Models\User;

final class PasskeyCredentialPolicy
{
    /**
     * A user may only delete their own passkeys.
     */
    public function delete(User $user, PasskeyCredential $passkeyCredential): bool
    {
        return $user->id === $passkeyCredential->user_id;
    }
}
