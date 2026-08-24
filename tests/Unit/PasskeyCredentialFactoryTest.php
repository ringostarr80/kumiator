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
     * Weicht der Handle im serialisierten Record von dem am Nutzer ab, weist die
     * Assertion-Zeremonie jedes so erzeugte Credential ab — und die Passkey-Tests
     * scheitern weit weg von der Ursache.
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
