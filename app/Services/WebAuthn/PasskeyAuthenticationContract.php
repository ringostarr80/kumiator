<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Models\User;
use Webauthn\PublicKeyCredentialRequestOptions;

interface PasskeyAuthenticationContract
{
    public function createOptions(?User $user = null): PublicKeyCredentialRequestOptions;

    public function verify(string $rawResponse, PublicKeyCredentialRequestOptions $storedOptions, string $host): User;
}
