<?php

declare(strict_types=1);

namespace Tests\Support;

use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

final class FixedSecretTwoFactorProvider implements TwoFactorAuthenticationProvider
{
    public function __construct(private readonly Google2FA $engine, private readonly string $fixedSecret)
    {
    }

    public function generateSecretKey(int $secretLength = 16): string
    {
        return $this->fixedSecret;
    }

    public function qrCodeUrl(mixed $companyName, mixed $companyEmail, mixed $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    public function verify(mixed $secret, mixed $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code);
    }
}
