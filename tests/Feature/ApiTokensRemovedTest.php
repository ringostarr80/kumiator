<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Hinter `auth:sanctum` öffnet ein API-Token die ganze Oberfläche — ohne Passkey
 * und auch bei abgeschaltetem Passwort-Login. Die Anwendung stellt deshalb keine aus.
 */
final class ApiTokensRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function testTheTokenPageIsGone(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/user/api-tokens')
            ->assertNotFound();
    }

    /**
     * `php artisan install:api` legt `/api/user` wieder an: eine Route, die jedem
     * Token das User-Model roh als JSON ausliefert, ohne Ability-Prüfung.
     */
    public function testTheApiUserEndpointIsGone(): void
    {
        $this->getJson('/api/user')
            ->assertNotFound();
    }

    /**
     * Eine Token-Zeile kann trotz Löschmigration auftauchen, etwa aus einem
     * älteren Backup. `auth:sanctum` schlägt jedes Bearer-Token nach; abgewiesen
     * wird es, weil der User `HasApiTokens` nicht trägt.
     */
    public function testALeftoverTokenOpensNoPage(): void
    {
        $bearer = $this->leftoverTokenFor(User::factory()->create());

        $this->withToken($bearer)
            ->get('/dashboard')
            ->assertRedirect(route('login'));
    }

    public function testTheMigrationDeletesExistingTokens(): void
    {
        $this->leftoverTokenFor(User::factory()->create());

        $migration = require database_path('migrations/2026_09_24_000000_delete_personal_access_tokens.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'up'));

        $migration->up();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    private function leftoverTokenFor(User $user): string
    {
        $plainText = Str::random(40);

        $token = new PersonalAccessToken([
            'name' => 'Altbestand',
            'token' => hash('sha256', $plainText),
            'abilities' => ['*'],
        ]);
        $token->tokenable()->associate($user);
        $token->saveOrFail();

        return sprintf('%d|%s', $token->id, $plainText);
    }
}
