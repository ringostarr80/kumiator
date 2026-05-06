<?php

declare(strict_types=1);

namespace App\Models\Contracts;

/**
 * Pendant zu `Illuminate\Contracts\Auth\MustVerifyEmail`: erlaubt Middleware
 * und Auth-Pfaden den Approval-Status eines Users abzufragen, ohne auf das
 * konkrete `App\Models\User`-Model zugreifen zu müssen.
 */
interface MustBeApproved
{
    public function isApproved(): bool;
}
