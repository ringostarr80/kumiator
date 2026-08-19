<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Services\WebAuthn\Contracts\WebAuthnCeremonySessionContract;
use Illuminate\Http\Request;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Hält den Session-Zustand, den beide Schritte einer WebAuthn-Zeremonie teilen.
 *
 * Registrierung und Anmeldung laufen nach demselben Zweischritt-Muster:
 * (1) Optionen erzeugen → in die Session legen, (2) Optionen aus der Session
 * ziehen → Antwort prüfen. Dieser Service bündelt die Session-Ein-/Ausgabe, damit
 * die Controller dünn bleiben und die Logik an einer Stelle getestet wird.
 */
final class WebAuthnCeremonySession implements WebAuthnCeremonySessionContract
{
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * Der abgelegte Wert trägt einen `expires_at`-Unix-Zeitstempel, damit
     * veraltete Challenges unabhängig von der Laravel-Session-Lebensdauer
     * verfallen. Die TTL steht in `webauthn.ceremony_session_ttl` (Sekunden).
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
     * Liefert `null`, wenn:
     * - der Session-Eintrag fehlt (nie gesetzt oder bereits verbraucht),
     * - der abgelegte Umschlag unbrauchbar ist (defekte oder manipulierte Session),
     * - die Challenge abgelaufen ist (siehe `webauthn.ceremony_session_ttl`),
     * - sich das JSON nicht in die erwartete Klasse deserialisieren lässt.
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
