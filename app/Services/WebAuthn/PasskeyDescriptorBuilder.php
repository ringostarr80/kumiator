<?php

declare(strict_types=1);

namespace App\Services\WebAuthn;

use App\Models\PasskeyCredential;
use Illuminate\Database\Eloquent\Collection;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Builds PublicKeyCredentialDescriptors from PasskeyCredential models.
 *
 * Used by both the registration and authentication services to populate
 * excludeCredentials / allowCredentials in WebAuthn ceremony options.
 */
final class PasskeyDescriptorBuilder
{
    /**
     * @param Collection<int, PasskeyCredential> $credentials
     * @return list<PublicKeyCredentialDescriptor>
     */
    public static function fromCollection(Collection $credentials): array
    {
        return array_values(
            $credentials
                ->map(fn (PasskeyCredential $c) => self::fromCredential($c))
                ->all(),
        );
    }

    public static function fromCredential(PasskeyCredential $credential): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(
            type: 'public-key',
            id: Base64UrlSafe::decodeNoPadding($credential->credential_id),
            transports: $credential->transports ?? [],
        );
    }
}
