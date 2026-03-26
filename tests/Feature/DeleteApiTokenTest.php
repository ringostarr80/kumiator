<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Http\Livewire\ApiTokenManager;
use Livewire\Livewire;
use Tests\TestCase;

final class DeleteApiTokenTest extends TestCase
{
    use RefreshDatabase;

    public function testApiTokensCanBeDeleted(): void
    {
        if (! Features::hasApiFeatures()) {
            $this->markTestSkipped('API support is not enabled.');
        }

        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => Str::random(40),
            'abilities' => ['create', 'read'],
        ]);

        Livewire::test(ApiTokenManager::class)
            ->set(['apiTokenIdBeingDeleted' => $token->id])
            ->call('deleteApiToken');

        $userTokens = $user->fresh()?->tokens;

        $this->assertNotNull($userTokens);
        $this->assertCount(0, $userTokens);
    }
}
