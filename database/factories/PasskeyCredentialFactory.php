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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Zufällige Credential-ID erzeugen (32 Bytes → Base64URL)
        $credentialIdBytes = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($credentialIdBytes);

        return [
            // Muss vor `credential_public_key` stehen: Der Record dort trägt den
            // Handle des Nutzers und kommt erst an ihn, wenn Laravel diesen Eintrag
            // zu einer ID aufgelöst hat.
            'user_id' => User::factory(),
            'credential_id' => $credentialId,
            'credential_public_key' => static fn (array $attributes): string => self::serializeRecord(
                $credentialIdBytes,
                $attributes['user_id'] ?? null,
            ),
            'counter' => 0,
            'transports' => ['internal'],
            'backup_eligible' => false,
            'backup_state' => false,
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'name' => fake()->words(2, true),
            'last_used_at' => null,
        ];
    }

    /**
     * Der Handle im Record muss zu dem am Nutzer passen, sonst weist die
     * Assertion-Zeremonie das Credential ab.
     */
    private static function serializeRecord(string $credentialIdBytes, mixed $userId): string
    {
        $user = User::query()->whereKey($userId)->firstOrFail();

        $record = CredentialRecord::create(
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
            userHandle: $user->getWebAuthnUserHandle(),
            counter: 0,
        );

        return app(SerializerInterface::class)->serialize($record, 'json');
    }
}
