<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use Illuminate\Http\Request;

interface WebAuthnCeremonySessionContract
{
    /**
     * @return array<mixed>
     */
    public function storeOptions(object $options, string $sessionKey, Request $request): array;

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function pullOptions(string $sessionKey, string $class, Request $request): ?object;
}
