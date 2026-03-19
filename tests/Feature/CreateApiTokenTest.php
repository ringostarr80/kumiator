<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager;
use Livewire\Livewire;
use Tests\TestCase;

class CreateApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function testApiTokensCanBeCreated(): void
    {
        if (! Features::hasApiFeatures()) {
            $this->markTestSkipped('API support is not enabled.');
        }

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        Livewire::test(ApiTokenManager::class)
            ->set(['createApiTokenForm' => [
                'name' => 'Test Token',
                'permissions' => [
                    'read',
                    'update',
                ],
            ]])
            ->call('createApiToken');

        $userTokens = $user->fresh()?->tokens;
        $this->assertNotNull($userTokens);
        $userFirstToken = $userTokens->first();
        $this->assertNotNull($userFirstToken);

        $this->assertCount(1, $userTokens);
        $this->assertEquals('Test Token', $userFirstToken->name);
        $this->assertTrue($userFirstToken->can('read'));
        $this->assertFalse($userFirstToken->can('delete'));
    }
}
