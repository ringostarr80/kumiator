<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Seeder läuft unter `WithoutModelEvents` und hängt damit den global
 * geteilten Model-Dispatcher aus. Der WebAuthn-Handle ist eine NOT-NULL-Spalte
 * ohne DB-Default: Entstünde er in einem Model-Event, bräche `db:seed` — und
 * mit ihm `migrate:fresh --seed` — an einem Constraint-Fehler ab.
 */
final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function testSeedingCreatesTheTestUserWithAWebAuthnHandle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'test@example.com')->sole();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $user->getWebAuthnUserHandle());
    }
}
