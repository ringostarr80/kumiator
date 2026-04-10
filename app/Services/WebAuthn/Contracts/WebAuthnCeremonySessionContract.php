<?php

declare(strict_types=1);

namespace App\Services\WebAuthn\Contracts;

use Illuminate\Http\Request;

interface WebAuthnCeremonySessionContract
{
    /**
     * Serialize $options, persist the JSON in the session under $sessionKey,
     * and return the null-stripped array ready for a JSON response.
     *
     * @return array<mixed>
     */
    public function storeOptions(object $options, string $sessionKey, Request $request): array;

    /**
     * Pull the serialized options from the session and deserialize them.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function pullOptions(string $sessionKey, string $class, Request $request): ?object;
}
