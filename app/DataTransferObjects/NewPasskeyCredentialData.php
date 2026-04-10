<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Carries the data extracted from a WebAuthn registration ceremony
 * that the repository needs to persist a new passkey credential.
 *
 * This DTO decouples the repository from the WebAuthn library types.
 */
final readonly class NewPasskeyCredentialData
{
    /**
     * @param string $credentialId Base64URL-encoded (no padding) raw credential ID.
     * @param string $serializedCredentialSource Full PublicKeyCredentialSource serialised to JSON.
     * @param int $counter Signature counter reported by the authenticator.
     * @param array<string> $transports Authenticator transport hints (e.g. "internal", "usb").
     * @param bool $backupEligible Whether the credential supports backup/sync (BE flag).
     * @param bool $backupState Whether the credential is currently backed up (BS flag).
     * @param string $aaguid Authenticator Attestation GUID as RFC 4122 UUID string.
     */
    public function __construct(
        public string $credentialId,
        public string $serializedCredentialSource,
        public int $counter,
        public array $transports,
        public bool $backupEligible,
        public bool $backupState,
        public string $aaguid,
    ) {
    }
}
