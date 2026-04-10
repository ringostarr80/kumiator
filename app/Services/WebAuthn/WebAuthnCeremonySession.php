<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use Illuminate\Http\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Handles the session-based state shared across both WebAuthn ceremony steps.
 *
 * Both the registration and authentication ceremonies follow the same two-step
 * pattern: (1) generate options → store in session, (2) pull options from session
 * → verify response. This service centralises that session I/O so the controllers
 * stay thin and the logic is tested in one place.
 */
final class WebAuthnCeremonySession implements WebAuthnCeremonySessionContract
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * Serialize $options, persist the JSON in the session under $sessionKey,
     * and return the null-stripped array ready for a JSON response.
     *
     * The stored value is wrapped with an `expires_at` Unix timestamp so that
     * stale challenges are rejected independently of the Laravel session lifetime.
     * The TTL is configured via `webauthn.ceremony_session_ttl` (seconds).
     *
     * @return array<mixed>
     */
    public function storeOptions(object $options, string $sessionKey, Request $request): array
    {
        $json = $this->serializer->serialize($options, 'json');

        $ttlRaw = config('webauthn.ceremony_session_ttl', 120);
        $ttl = is_int($ttlRaw)
            ? $ttlRaw
            : 120;

        $request->session()->put($sessionKey, [
            'data' => $json,
            'expires_at' => now()->addSeconds($ttl)->timestamp,
        ]);

        return WebAuthnJsonNormalizer::normalizeOptionsJson($json);
    }

    /**
     * Pull the serialized options from the session and deserialize them.
     *
     * Returns null when:
     * - the session entry is missing (never set or already consumed),
     * - the stored envelope is malformed (corrupted or tampered session),
     * - the challenge has expired (see `webauthn.ceremony_session_ttl`),
     * - the JSON cannot be deserialized into the expected class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function pullOptions(string $sessionKey, string $class, Request $request): ?object
    {
        $stored = $request->session()->pull($sessionKey);

        if (
            !is_array($stored)
            || !isset($stored['data'], $stored['expires_at'])
            || now()->timestamp > $stored['expires_at']
        ) {
            return null;
        }

        try {
            $result = $this->serializer->deserialize($stored['data'], $class, 'json');
        } catch (\Throwable) {
            return null;
        }

        return $result instanceof $class
            ? $result
            : null; // @codeCoverageIgnore
    }
}
