<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\PendingCommand;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * E-Mail-Identität ist case-insensitiv (DB-Collation NOCASE). Sichert die
 * Wirkung über alle Pfade ab — auch die Fortify-umgehenden CLI-Wege.
 */
final class EmailCaseInsensitivityTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'migrations/2026_06_19_000000_make_users_email_case_insensitive.php';
    private const string EMAIL = 'case@example.com';
    private const string EMAIL_MIXED_CASE = 'CASE@example.com';

    public function testLookupIgnoresCase(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->assertTrue(
            User::query()->where('email', self::EMAIL_MIXED_CASE)->exists(),
        );
    }

    public function testUniqueIndexRejectsCaseOnlyDuplicate(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => self::EMAIL_MIXED_CASE]);
    }

    public function testUniqueValidationRuleRejectsCaseOnlyDuplicate(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $validator = Validator::make(
            ['email' => self::EMAIL_MIXED_CASE],
            ['email' => ['unique:users']],
        );

        $this->assertTrue($validator->fails());
    }

    public function testConsoleLookupFindsUserDespiteCaseDifference(): void
    {
        $user = User::factory()->unapproved()->create(['email' => self::EMAIL]);

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::EMAIL_MIXED_CASE)
            ->expectsOutputToContain(__('commands.approve_user.success', [
                'name' => $user->name,
                'email' => self::EMAIL_MIXED_CASE,
            ]))
            ->assertSuccessful()
            ->run();

        $this->assertNotNull($user->fresh()?->approved_at);
    }

    public function testMigrationAbortsOnExistingCaseCollision(): void
    {
        // Spalte case-sensitiv machen, damit zwei nur in der Schreibweise
        // verschiedene Adressen überhaupt nebeneinander existieren können.
        $this->setEmailCollation('binary');

        User::factory()->create(['email' => 'collision@example.com']);
        User::factory()->create(['email' => 'COLLISION@example.com']);

        $loaded = require database_path(self::MIGRATION);

        if (!$loaded instanceof Migration) {
            self::fail('Migration konnte nicht geladen werden.');
        }

        // `up()` ist nur auf der anonymen Subklasse deklariert, nicht am
        // Migration-Basistyp — daher per Reflection aufrufen.
        $up = new ReflectionMethod($loaded, 'up');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('kollidieren case-insensitiv');

        try {
            $up->invoke($loaded);
        } finally {
            // Der Guard wirft vor der Collation-Änderung; Spalte bleibt binary.
            // Kollisionen entfernen und NOCASE wiederherstellen, damit weitere
            // Tests im selben Prozess die erwartete Schema-Lage vorfinden.
            User::query()
                ->whereIn('email', ['collision@example.com', 'COLLISION@example.com'])
                ->forceDelete();
            $this->setEmailCollation('nocase');
        }
    }

    private function setEmailCollation(string $collation): void
    {
        Schema::table('users', static function (Blueprint $table) use ($collation): void {
            $table->string('email')->collation($collation)->change();
        });
    }
}
