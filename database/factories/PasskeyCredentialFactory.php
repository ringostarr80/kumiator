<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * @extends Factory<PasskeyCredential>
 */
class PasskeyCredentialFactory extends Factory
{
    protected $model = PasskeyCredential::class;

    public function configure(): static
    {
        return $this->afterCreating(function (PasskeyCredential $credential): void {
            $user = $credential->user()->firstOrFail();
            $serializer = app(SerializerInterface::class);

            $source = $serializer->deserialize(
                $credential->credential_public_key,
                CredentialRecord::class,
                'json',
            );

            $corrected = CredentialRecord::create(
                publicKeyCredentialId: $source->publicKeyCredentialId,
                type: $source->type,
                transports: $source->transports,
                attestationType: $source->attestationType,
                trustPath: $source->trustPath,
                aaguid: $source->aaguid,
                credentialPublicKey: $source->credentialPublicKey,
                userHandle: $user->getWebAuthnUserHandle(),
                counter: $source->counter,
                otherUI: $source->otherUI,
                backupEligible: $source->backupEligible,
                backupStatus: $source->backupStatus,
                uvInitialized: $source->uvInitialized,
            );

            PasskeyCredential::withoutEvents(
                fn (): bool => $credential->updateOrFail([
                    'credential_public_key' => $serializer->serialize($corrected, 'json'),
                ]),
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Zufällige Credential-ID erzeugen (32 Bytes → Base64URL)
        $credentialIdBytes = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($credentialIdBytes);

        // Minimalen CredentialRecord bauen und für die Ablage serialisieren
        $credentialRecord = CredentialRecord::create(
            publicKeyCredentialId: $credentialIdBytes,
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            // 77 Bytes ≈ kleinstmögliche CBOR-Kodierung eines ES256-COSE-Schlüssels
            // (EC2/P-256): 1 Byte Map-Header + 5 Paare (kty, alg, crv, x[32], y[32]).
            // Der Wert wird in Tests nie kryptografisch geprüft; für den Roundtrip
            // durch den Serializer genügt jede nicht-leere Bytefolge dieser Länge.
            credentialPublicKey: random_bytes(77),
            userHandle: '0', // Platzhalter – afterCreating() setzt die echte User-ID ein
            counter: 0,
        );

        $serializer = app(SerializerInterface::class);
        $serialisedRecord = $serializer->serialize($credentialRecord, 'json');

        return [
            'user_id' => User::factory(),
            'credential_id' => $credentialId,
            'credential_public_key' => $serialisedRecord,
            'counter' => 0,
            'transports' => ['internal'],
            'backup_eligible' => false,
            'backup_state' => false,
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => fake()->words(2, true),
            'last_used_at' => null,
        ];
    }
}
