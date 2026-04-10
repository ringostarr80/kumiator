<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use App\Models\PasskeyCredential;
use App\Models\User;
use Webauthn\PublicKeyCredentialCreationOptions;

interface PasskeyRegistrationContract
{
    public function createOptions(User $user): PublicKeyCredentialCreationOptions;

    public function verifyAndSave(
        User $user,
        string $rawResponse,
        PublicKeyCredentialCreationOptions $storedOptions,
        string $credentialName,
        string $host,
    ): PasskeyCredential;
}
