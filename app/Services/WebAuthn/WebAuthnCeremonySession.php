<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use Illuminate\Http\Request;

/**
 * Handles the session-based state shared across both WebAuthn ceremony steps.
 *
 * Both the registration and authentication ceremonies follow the same two-step
 * pattern: (1) generate options → store in session, (2) pull options from session
 * → verify response. This service centralises that session I/O so the controllers
 * stay thin and the logic is tested in one place.
 */
final class WebAuthnCeremonySession
{
    public function __construct(private readonly WebAuthnServerService $serverService)
    {
    }

    /**
     * Serialize $options, persist the JSON in the session under $sessionKey,
     * and return the null-stripped array ready for a JSON response.
     *
     * @return array<mixed>
     */
    public function storeOptions(object $options, string $sessionKey, Request $request): array
    {
        $json = $this->serverService->getSerializer()->serialize($options, 'json');

        $request->session()->put($sessionKey, $json);

        return $this->serverService->normalizeOptionsJson($json);
    }

    /**
     * Pull the serialized options from the session and deserialize them.
     *
     * Returns null when the session entry is missing (e.g. expired or never set),
     * so the caller can return the appropriate 422 response.
     *
     * Throws \UnexpectedValueException when the session data cannot be deserialized
     * to the expected class (corrupted or tampered session).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function pullOptions(string $sessionKey, string $class, Request $request): ?object
    {
        $json = $request->session()->pull($sessionKey);

        if ($json === null) {
            return null;
        }

        $result = $this->serverService->getSerializer()->deserialize($json, $class, 'json');

        if (!($result instanceof $class)) {
            // @codeCoverageIgnoreStart
            throw new \UnexpectedValueException(
                "WebAuthn session data is corrupted: expected {$class}, got " . get_debug_type($result),
            );
            // @codeCoverageIgnoreEnd
        }

        return $result;
    }
}
