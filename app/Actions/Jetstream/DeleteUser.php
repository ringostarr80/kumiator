<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesUsers;

class DeleteUser implements DeletesUsers
{
    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            $user->tokens->each->delete();
            $user->delete();
        });

        $user->deleteProfilePhoto();
    }
}
