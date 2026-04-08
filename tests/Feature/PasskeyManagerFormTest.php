<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\PasskeyManagerForm;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

final class PasskeyManagerFormTest extends TestCase
{
    use RefreshDatabase;

    public function testComponentRendersForAuthenticatedUser(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertOk();
    }

    public function testComponentListsUsersPasskeys(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->count(2)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 2);
    }

    public function testComponentDoesNotListOtherUsersPasskeys(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PasskeyCredential::factory()->for($other)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 0);
    }

    public function testPasskeysAreOrderedNewestFirst(): void
    {
        $user = User::factory()->create();
        $old = PasskeyCredential::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $new = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertSet('passkeys.0.id', $new->id)
            ->assertSet('passkeys.1.id', $old->id); // @phpstan-ignore method.nonObject
    }

    public function testLoadPasskeysAbortsWhenAuthUserIsNull(): void
    {
        Auth::shouldReceive('user')->andReturnNull();

        Livewire::test(PasskeyManagerForm::class)->assertStatus(401);
    }

    public function testOwnerCanDeletePasskey(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id);

        $this->assertModelMissing($passkey);
    }

    public function testDeletePasskeyRefreshesPasskeyList(): void
    {
        $user = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($user)->create();

        Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 1)
            ->call('deletePasskey', $passkey->id) // @phpstan-ignore method.nonObject
            ->assertCount('passkeys', 0); // @phpstan-ignore method.nonObject
    }

    public function testPasskeyRegisteredEventRefreshesPasskeyList(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->assertCount('passkeys', 0);

        PasskeyCredential::factory()->for($user)->create();

        $component->dispatch('passkey-registered') // @phpstan-ignore method.nonObject
            ->assertCount('passkeys', 1); // @phpstan-ignore method.nonObject
    }

    public function testOtherUserCannotDeletePasskey(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $passkey = PasskeyCredential::factory()->for($owner)->create();

        Livewire::actingAs($other)
            ->test(PasskeyManagerForm::class) // @phpstan-ignore argument.templateType
            ->call('deletePasskey', $passkey->id)
            ->assertForbidden();

        $this->assertModelExists($passkey);
    }
}
