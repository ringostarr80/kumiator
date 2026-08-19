<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Config\Vendor\Webauthn\WebauthnConfig;
use App\Models\PasskeyCredential;
use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Ein Authenticator aus Software: erzeugt ein ES256-Schlüsselpaar und signiert
 * damit Assertions so, wie ein echter Passkey es täte.
 *
 * Damit läuft die Assertion-Zeremonie in Tests durch den echten Validator,
 * statt an der Service-Grenze weggemockt zu werden.
 */
final class VirtualAuthenticator
{
    /** Flags-Byte in `authenticatorData`: User Present + User Verified. */
    private const int FLAGS_UP_AND_UV = 0x05;

    /** Feste Koordinatenlänge einer P-256-Kurve in Byte. */
    private const int P256_COORDINATE_LENGTH = 32;

    private function __construct(private readonly \OpenSSLAsymmetricKey $privateKey, private readonly string $coseKey)
    {
    }

    public static function create(): self
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException('Could not generate a P-256 key pair.');
        }

        $details = openssl_pkey_get_details($privateKey);
        $curvePoint = is_array($details)
            ? $details['ec'] ?? null
            : null;
        $x = is_array($curvePoint)
            ? $curvePoint['x'] ?? null
            : null;
        $y = is_array($curvePoint)
            ? $curvePoint['y'] ?? null
            : null;

        if (!is_string($x) || !is_string($y)) {
            throw new \RuntimeException('Could not read the public curve point.');
        }

        return new self($privateKey, self::encodeCoseKey($x, $y));
    }

    /**
     * Legt einen Passkey für $user an, dessen hinterlegter öffentlicher
     * Schlüssel zu diesem Authenticator gehört — nur so kann die spätere
     * Signaturprüfung überhaupt gelingen.
     */
    public function registerFor(User $user): PasskeyCredential
    {
        $credential = PasskeyCredential::factory()->for($user)->create();
        $serializer = app(SerializerInterface::class);

        $record = $serializer->deserialize($credential->credential_public_key, CredentialRecord::class, 'json');
        $record->credentialPublicKey = $this->coseKey;

        PasskeyCredential::withoutEvents(
            fn (): bool => $credential->updateOrFail([
                'credential_public_key' => $serializer->serialize($record, 'json'),
            ]),
        );

        return $credential;
    }

    /**
     * Baut die JSON-Antwort, die der Browser nach `navigator.credentials.get()`
     * an den Server schickt.
     *
     * `$withUserHandle` bildet den Unterschied zwischen discoverable Credentials
     * (Authenticator kennt den Handle und liefert ihn mit) und nicht-discoverable
     * Credentials (kein Handle in der Antwort) ab; `$userHandleOverride` liefert
     * einen abweichenden Handle, wie ihn nur ein manipulierter Client sendet.
     */
    public function signAssertion(
        PasskeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        int $counter = 1,
        bool $withUserHandle = true,
        ?string $userHandleOverride = null,
    ): string {
        $clientDataJson = (string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded($options->challenge),
            'origin' => WebauthnConfig::appUrl(),
            'crossOrigin' => false,
        ]);

        // Binärformat laut WebAuthn-Spezifikation:
        // 32 Byte RP-ID-Hash | 1 Byte Flags | 4 Byte Signaturzähler (big-endian).
        $authenticatorData = hash('sha256', WebauthnConfig::effectiveHost(), true)
            . chr(self::FLAGS_UP_AND_UV)
            . pack('N', $counter);

        $signature = '';
        $signed = openssl_sign(
            $authenticatorData . hash('sha256', $clientDataJson, true),
            $signature,
            $this->privateKey,
            OPENSSL_ALGO_SHA256,
        );

        // Einen Fehlschlag meldet `openssl_sign()` nur im Rückgabewert und lässt
        // `$signature` auf dem Initialwert stehen. Die Typprüfung daneben bleibt
        // nötig, weil die statische Analyse den By-Reference-Parameter als `mixed`
        // sieht.
        if (!$signed || !is_string($signature)) {
            throw new \RuntimeException('Could not sign the assertion.');
        }

        $response = [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientDataJson),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
        ];

        if ($withUserHandle) {
            $handle = $userHandleOverride ?? $credential->user()->firstOrFail()->getWebAuthnUserHandle();
            $response['userHandle'] = Base64UrlSafe::encodeUnpadded($handle);
        }

        return (string) json_encode([
            'id' => $credential->credential_id,
            'rawId' => $credential->credential_id,
            'response' => $response,
            'type' => 'public-key',
        ]);
    }

    /**
     * COSE-Schlüssel für EC2/P-256 (RFC 8152): kty, alg, crv und die beiden
     * Koordinaten unter den dort festgelegten Label-Nummern.
     */
    private static function encodeCoseKey(string $x, string $y): string
    {
        return (string) MapObject::create()
            // kty = EC2
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            // alg = ES256
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            // crv = P-256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            // x-Koordinate
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(self::padCoordinate($x)))
            // y-Koordinate
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(self::padCoordinate($y)));
    }

    /**
     * OpenSSL liefert Koordinaten ohne führende Nullbytes, COSE verlangt die
     * volle Kurvenbreite.
     */
    private static function padCoordinate(string $coordinate): string
    {
        return str_pad($coordinate, self::P256_COORDINATE_LENGTH, "\0", STR_PAD_LEFT);
    }
}
