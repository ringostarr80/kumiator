<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * Trägt die Daten aus einer abgeschlossenen Registrierungszeremonie, die das
 * Repository zum Anlegen eines neuen Passkeys braucht.
 *
 * Das DTO entkoppelt das Repository von den Typen der WebAuthn-Bibliothek.
 */
final readonly class NewPasskeyCredentialData
{
    /**
     * @param string $credentialId Rohe Credential-ID, Base64URL-codiert (ohne Padding).
     * @param string $serializedCredentialSource Vollständiger CredentialRecord als JSON.
     * @param int $counter Signaturzähler, wie ihn der Authenticator meldet.
     * @param array<string> $transports Transport-Hinweise des Authenticators (z. B. "internal", "usb").
     * @param bool $backupEligible Ob das Credential Backup/Sync beherrscht (BE-Flag).
     * @param bool $backupState Ob das Credential derzeit gesichert ist (BS-Flag).
     * @param string $aaguid Authenticator Attestation GUID als UUID-String nach RFC 4122.
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
