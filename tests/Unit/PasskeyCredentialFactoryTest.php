<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Serializer\SerializerInterface;
use Tests\TestCase;
use Webauthn\CredentialRecord;

final class PasskeyCredentialFactoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der serialisierte CredentialRecord trägt in `definition()` bewusst den
     * Platzhalter-userHandle `'0'`; erst der `afterCreating`-Hook stempelt die
     * echte User-ID ein. Weil Laravels Factory immutable ist, geht dieser Hook
     * verloren, sobald `configure()` den Rückgabewert von `afterCreating()`
     * verwirft — der Record behält dann `'0'`, was zu `getWebAuthnUserHandle()`
     * nicht mehr passt.
     */
    public function testFactoryStampsRealUserHandleOntoCredentialRecord(): void
    {
        $user = User::factory()->create();

        $credential = PasskeyCredential::factory()->for($user)->create();

        $record = app(SerializerInterface::class)->deserialize(
            $credential->credential_public_key,
            CredentialRecord::class,
            'json',
        );

        $this->assertSame($user->getWebAuthnUserHandle(), $record->userHandle);
    }
}
