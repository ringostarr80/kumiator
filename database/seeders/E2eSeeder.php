<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Jeder Browser-Test meldet sich mit einem eigenen Konto an: Die Tests verändern
 * es und dürfen sich dabei nicht in die Quere kommen, egal in welcher Reihenfolge
 * sie laufen.
 */
class E2eSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RoleSeeder::class);

        foreach (['e2e-two-factor@example.com', 'e2e-browser-sessions@example.com'] as $email) {
            User::factory()->create(['email' => $email])->assignRole('member');
        }
    }
}
