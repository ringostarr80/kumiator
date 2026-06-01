<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\User\Contracts\UserApproverContract;
use Illuminate\Support\Carbon;

/**
 * Setzt `approved_at` und persistiert mit `saveOrFail()`.
 *
 * Kein expliziter Activity-Log-Schreibvorgang: Der `user_approved`-Eintrag
 * entsteht automatisch über den `LogsActivity`-Trait (`approved_at` ist in
 * `User::getActivitylogOptions()->logOnly(...)`) plus den `Activity::saving`-
 * Hook, der `updated` → `user_approved` mappt. Begründung siehe Contract.
 */
final class UserApprover implements UserApproverContract
{
    public function approve(User $user): void
    {
        $user->approved_at = Carbon::now();
        $user->saveOrFail();
    }
}
