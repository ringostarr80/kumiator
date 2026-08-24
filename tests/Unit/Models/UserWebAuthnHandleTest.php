<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserWebAuthnHandleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Der Handle landet auf dem Authenticator und im Cloud-Sync des Anbieters.
     * Eine fortlaufende Zahl verriete dort Registrierungsreihenfolge und
     * Größenordnung der Nutzerbasis, deshalb 32 Zufallsbytes als Base64URL.
     */
    public function testANewUserGetsARandomHandle(): void
    {
        $user = User::factory()->create();

        $handle = $user->getWebAuthnUserHandle();

        $this->assertSame(43, strlen($handle));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $handle);
        $this->assertNotSame((string)$user->id, $handle);
    }

    public function testTwoUsersGetDifferentHandles(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->assertNotSame($first->getWebAuthnUserHandle(), $second->getWebAuthnUserHandle());
    }

    /**
     * Der Handle entsteht im Insert-Pfad, nicht in einem Model-Event: `Event::fake()`,
     * `Model::withoutEvents()`, `saveQuietly()` und der Seeder-Trait `WithoutModelEvents`
     * hängen alle denselben global geteilten Dispatcher aus. Die Spalte ist NOT NULL,
     * ein ausgefallener Hook wäre also ein Constraint-Fehler weit weg von der Ursache.
     */
    public function testTheHandleIsSetEvenWhenModelEventsAreMuted(): void
    {
        $user = Model::withoutEvents(static fn (): User => User::factory()->create());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $user->getWebAuthnUserHandle());
    }

    /**
     * Ändert sich der Handle, verwaisen alle bereits registrierten Passkeys des
     * Kontos — er muss jede spätere Änderung am Nutzer überleben.
     */
    public function testTheHandleSurvivesAnUpdateOfTheUser(): void
    {
        $user = User::factory()->create();
        $handle = $user->getWebAuthnUserHandle();

        $user->updateOrFail(['name' => 'Neuer Name']);

        $this->assertSame($handle, $user->refresh()->getWebAuthnUserHandle());
    }

    /**
     * Der Handle entsteht erst im Insert. Auf einer nie gespeicherten Instanz
     * gibt es ihn nicht — und ein `TypeError` aus dem Rückgabetyp benennt zwar
     * die Methode, nicht aber die Ursache.
     */
    public function testTheHandleGetterNamesTheCauseOnAnUnsavedUser(): void
    {
        $user = User::factory()->make();

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessageIs(
            'The attribute [webauthn_user_handle] either does not exist or was not retrieved for model ['
            . User::class . '].',
        );

        $user->getWebAuthnUserHandle();
    }

    /**
     * Eine Teil-Selektion lädt die Spalte nicht mit; das Model existiert dann,
     * trägt den Handle aber nicht.
     */
    public function testTheHandleGetterNamesTheCauseOnAPartiallySelectedUser(): void
    {
        User::factory()->create();
        $user = User::query()->select('id')->sole();

        $this->expectException(MissingAttributeException::class);

        $user->getWebAuthnUserHandle();
    }
}
