<?php

declare(strict_types=1);

namespace Tests\Support;

use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\AttestationStatement\AttestationStatementSupport;
use Webauthn\AuthenticatorData;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Ein Attestation-Format, das nur der Manager der App kennt. Damit lässt
 * sich prüfen, ob Serializer und Zeremonie denselben Manager verwenden:
 * Kennt nur einer das Format, scheitert die Registrierung.
 */
final class FakeAttestationStatementSupport implements AttestationStatementSupport
{
    public const string FORMAT = 'fake';

    public function name(): string
    {
        return self::FORMAT;
    }

    /**
     * @param array<string, mixed> $attestation
     */
    public function load(array $attestation): AttestationStatement
    {
        return AttestationStatement::createNone(self::FORMAT, [], EmptyTrustPath::create());
    }

    public function isValid(
        string $clientDataJSONHash,
        AttestationStatement $attestationStatement,
        AuthenticatorData $authenticatorData,
    ): bool {
        return true;
    }
}
