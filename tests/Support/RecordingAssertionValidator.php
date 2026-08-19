<?php

declare(strict_types=1);

namespace Tests\Support;

use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Zeichnet die Zeremonie-Aufrufe des echten Validators auf.
 *
 * Nötig, weil die Fake-Verifikation ihren Fehler absichtlich schluckt: Ohne
 * Mitschnitt lässt sich von außen nicht beobachten, an welchem Zeremonie-Schritt
 * sie endet — und genau das entscheidet über die Laufzeit-Symmetrie.
 */
final class RecordingAssertionValidator extends AuthenticatorAssertionResponseValidator
{
    /**
     * @var list<array{record: CredentialRecord, userHandle: string|null, error: \Throwable|null}>
     */
    private array $calls = [];

    public function check(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse $authenticatorAssertionResponse,
        PublicKeyCredentialRequestOptions $publicKeyCredentialRequestOptions,
        string $host,
        ?string $userHandle,
    ): CredentialRecord {
        try {
            $record = parent::check(
                $credentialRecord,
                $authenticatorAssertionResponse,
                $publicKeyCredentialRequestOptions,
                $host,
                $userHandle,
            );
        } catch (\Throwable $e) {
            $this->calls[] = ['record' => $credentialRecord, 'userHandle' => $userHandle, 'error' => $e];

            throw $e;
        }

        $this->calls[] = ['record' => $credentialRecord, 'userHandle' => $userHandle, 'error' => null];

        return $record;
    }

    /** @return list<array{record: CredentialRecord, userHandle: string|null, error: \Throwable|null}> */
    public function calls(): array
    {
        return $this->calls;
    }
}
