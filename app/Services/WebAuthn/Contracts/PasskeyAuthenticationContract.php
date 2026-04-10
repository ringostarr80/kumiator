<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use App\Models\User;
use Webauthn\PublicKeyCredentialRequestOptions;

interface PasskeyAuthenticationContract
{
    public function createOptions(?User $user = null): PublicKeyCredentialRequestOptions;

    public function verify(string $rawResponse, PublicKeyCredentialRequestOptions $storedOptions, string $host): User;

    /**
     * Run a fake credential DB lookup to equalise response time between known
     * and unknown e-mail addresses, preventing timing-based e-mail enumeration
     * on the options endpoint.
     */
    public function runFakeCredentialLookup(): void;
}
