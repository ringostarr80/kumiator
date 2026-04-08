<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\WebAuthn\WebAuthnServerService;
use Illuminate\Database\Eloquent\Factories\Factory;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * @extends Factory<PasskeyCredential>
 */
class PasskeyCredentialFactory extends Factory
{
    protected $model = PasskeyCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a random credential ID (32 bytes → Base64URL)
        $credentialIdBytes = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($credentialIdBytes);

        // Build a minimal PublicKeyCredentialSource and serialise it for storage
        $credentialRecord = PublicKeyCredentialSource::create(
            publicKeyCredentialId: $credentialIdBytes,
            type: 'public-key',
            transports: ['internal'],
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            // 77 bytes ≈ minimum CBOR-encoded size of an ES256 (EC2/P-256) COSE public key:
            // 1 byte map header + 5 key-value pairs (kty, alg, crv, x[32], y[32]).
            // The value is never cryptographically verified in tests; any non-empty byte
            // string of plausible length is sufficient for the serializer to round-trip.
            credentialPublicKey: random_bytes(77),
            userHandle: (string) fake()->uuid(),
            counter: 0,
        );

        $serializer = app(WebAuthnServerService::class)->getSerializer();
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
