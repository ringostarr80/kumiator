<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\PendingCommand;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Spatie\Permission\Models\Role;
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

    // SQLites NOCASE faltet nur ASCII A–Z. Alles jenseits davon muss die
    // Anwendung selbst normalisieren, sonst driften Schreib- und Lesepfad
    // auseinander (Fortify senkt Login-Eingaben mb-basiert).
    private const string EMAIL_UMLAUT = 'müller@example.com';
    private const string EMAIL_UMLAUT_MIXED_CASE = 'MÜLLER@Example.com';

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

    public function testStoredEmailIsNormalizedToLowercase(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL_UMLAUT_MIXED_CASE]);

        $this->assertSame(self::EMAIL_UMLAUT, $user->fresh()?->email);
    }

    public function testConsoleCreateStoresNormalizedEmail(): void
    {
        Role::findOrCreate('member');

        $this->runCreateCommand(self::EMAIL_UMLAUT_MIXED_CASE)->assertSuccessful()->run();

        $this->assertSame(self::EMAIL_UMLAUT, User::query()->sole()->email);
    }

    /**
     * Das Kernszenario: Fortify senkt die Login-Eingabe mb-basiert, der Lookup
     * sucht danach nach `müller@…`. Wird die Adresse unnormalisiert gespeichert,
     * greift NOCASE bei `Ü` nicht — der Nutzer kommt dauerhaft nicht hinein.
     */
    public function testLoginSucceedsForEmailWithNonAsciiUppercase(): void
    {
        User::factory()->create(['email' => self::EMAIL_UMLAUT_MIXED_CASE]);

        $this->post('/login', [
            'email' => self::EMAIL_UMLAUT_MIXED_CASE,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function testUniqueIndexRejectsNonAsciiCaseOnlyDuplicate(): void
    {
        User::factory()->create(['email' => self::EMAIL_UMLAUT]);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => self::EMAIL_UMLAUT_MIXED_CASE]);
    }

    public function testConsoleCreateRejectsNonAsciiCaseOnlyDuplicate(): void
    {
        Role::findOrCreate('member');
        User::factory()->create(['email' => self::EMAIL_UMLAUT]);

        $this->runCreateCommand(self::EMAIL_UMLAUT_MIXED_CASE)->assertFailed()->run();

        $this->assertSame(1, User::query()->count());
    }

    public function testConsoleLookupFindsUserDespiteNonAsciiCaseDifference(): void
    {
        $user = User::factory()->unapproved()->create(['email' => self::EMAIL_UMLAUT]);

        /** @var PendingCommand $command */
        $command = $this->artisan('user:approve');

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::EMAIL_UMLAUT_MIXED_CASE)
            ->assertSuccessful()
            ->run();

        $this->assertNotNull($user->fresh()?->approved_at);
    }

    public function testProfileEmailChangeStoresNormalizedPendingEmail(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => self::EMAIL]));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'name' => $user->name,
                'email' => self::EMAIL_UMLAUT_MIXED_CASE,
                'current_password' => 'password',
            ])
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame(self::EMAIL_UMLAUT, $user->fresh()?->pending_email);
    }

    public function testOwnEmailInDifferentNonAsciiCaseIsNotTreatedAsChange(): void
    {
        Notification::fake();
        $this->actingAs($user = User::factory()->create(['email' => self::EMAIL_UMLAUT]));

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => $user->name, 'email' => self::EMAIL_UMLAUT_MIXED_CASE])
            ->call('updateProfileInformation')
            ->assertHasNoErrors('current_password');

        $this->assertNull($user->fresh()?->pending_email);
        Notification::assertNothingSent();
    }

    public function testMigrationAbortsOnExistingCaseCollision(): void
    {
        // Spalte case-sensitiv machen, damit zwei nur in der Schreibweise
        // verschiedene Adressen überhaupt nebeneinander existieren können.
        $this->setEmailCollation('binary');

        User::factory()->create(['email' => 'collision@example.com']);
        $colliding = User::factory()->create(['email' => 'second@example.com']);

        // Am `email`-Mutator vorbei: ein Mass-Update über den Query-Builder
        // wendet keine Attribut-Mutatoren an. Über die Factory ließe sich die
        // Kollision, gegen die der Guard schützt, nicht mehr herstellen.
        User::query()
            ->whereKey($colliding->getKey())
            ->update(['email' => 'COLLISION@example.com']);

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

    private function runCreateCommand(string $email): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('user:create');

        return $command
            ->expectsQuestion(__('commands.create_user.ask_name'), 'Erika Mustermann')
            ->expectsQuestion(__('commands.common.ask_email'), $email)
            ->expectsQuestion(__('commands.create_user.ask_password'), 'password123')
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), 'password123')
            ->expectsChoice(__('commands.create_user.ask_role'), 'member', ['member']);
    }

    private function setEmailCollation(string $collation): void
    {
        Schema::table('users', static function (Blueprint $table) use ($collation): void {
            $table->string('email')->collation($collation)->change();
        });
    }
}
