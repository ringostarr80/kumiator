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

final class ApiTokenPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function testApiTokenPermissionsCanBeUpdated(): void
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
            ->set(['managingPermissionsFor' => $token])
            ->set(['updateApiTokenForm' => [
                'permissions' => [
                    'delete',
                    'missing-permission',
                ],
            ]])
            ->call('updateApiToken');

        $userFirstAccessToken = $user->fresh()?->tokens->first();

        $this->assertNotNull($userFirstAccessToken);
        $this->assertTrue($userFirstAccessToken->can('delete'));
        $this->assertFalse($userFirstAccessToken->can('read'));
        $this->assertFalse($userFirstAccessToken->can('missing-permission'));
    }
}
