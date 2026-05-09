<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\User;
use App\Services\Console\ConsoleActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Validiert das CLI-Actor- & Event-Remap-Verhalten für Artisan-Commands:
 *   - dedizierte Event-Codes (`user_created_via_cli`, `user_approved_via_cli`,
 *     `user_deleted_via_cli`, `user_restored_via_cli`, `password_reset_via_cli`)
 *     statt generischer Eloquent-Events bzw. statt fehlendem Eintrag,
 *   - `cli_actor`-Property mit os_user/hostname/command (nested) auf jedem
 *     Eintrag, der während einer Command-Ausführung entsteht,
 *   - sauberes Lifecycle-Verhalten: kein Doppel-Logging, Kontext nach
 *     `CommandFinished` wieder leer.
 *
 * Mechanik: Ein Listener (`CaptureConsoleActorListener`) füllt den
 * `ConsoleActorContext`-Marker aus `Illuminate\Console\Events\CommandStarting`,
 * der zentrale `Activity::saving`-Hook im `AppServiceProvider` wendet die
 * Anreicherung und das Relabeling an, und `CommandFinished` cleart wieder.
 */
final class ConsoleActivityLogTest extends TestCase
{
    use RefreshDatabase;
    // Im Test-Modus stellt Laravel das Rerouting von Symfony-Console-Events
    // auf `Illuminate\Console\Events\CommandStarting`/`CommandFinished`
    // standardmäßig NICHT her (siehe `Foundation\Console\Kernel::__construct()`
    // → `runningUnitTests()`-Guard). Ohne diesen Trait würde unser
    // `CaptureConsoleActorListener` für ein `$this->artisan(...)` schlicht
    // nicht gefeuert, der `ConsoleActorContext` bliebe leer und alle
    // CLI-Actor-Assertions schlügen fehl. In Production-CLI ist das Rerouting
    // automatisch aktiv — daher ist der Trait hier rein test-spezifisch.
    use WithConsoleEvents;

    private const string ACTOR_EMAIL = 'admin-actor@example.com';
    private const string TARGET_EMAIL = 'target@example.com';
    private const string TARGET_NAME = 'Target User';
    private const string PASSWORD = 'Password!1234';

    public function testUserCreateRelabelsToUserCreatedViaCli(): void
    {
        Activity::query()->delete();

        $command = $this->artisan('user:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_user.ask_name'), self::TARGET_NAME)
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsQuestion(__('commands.create_user.ask_password'), self::PASSWORD)
            ->expectsQuestion(__('commands.create_user.ask_password_confirm'), self::PASSWORD)
            ->expectsChoice(__('commands.create_user.ask_role'), 'member', ['admin', 'member'])
            ->assertSuccessful()
            ->run();

        $user = User::query()->where('email', self::TARGET_EMAIL)->firstOrFail();

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'user_created_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Erwarteter user_created_via_cli-Eintrag fehlt.');
        $this->assertSame(__('app.activity_user_created_via_cli'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:create');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'created')
                ->count(),
            'Generischer user.created-Eintrag darf neben dem Relabel nicht stehen bleiben.',
        );
    }

    public function testUserApproveRelabelsToUserApprovedViaCli(): void
    {
        $user = User::factory()->unapproved()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'user_approved_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_approved_via_cli'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:approve');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'updated')
                ->count(),
            'Generischer user.updated-Eintrag darf neben dem Relabel nicht stehen bleiben.',
        );
    }

    public function testUserDeleteRelabelsToUserDeletedViaCli(): void
    {
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'user_deleted_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_deleted_via_cli'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:delete');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'deleted')
                ->count(),
            'Generischer user.deleted-Eintrag darf neben dem Relabel nicht stehen bleiben.',
        );
    }

    public function testUserRestoreRelabelsToUserRestoredViaCli(): void
    {
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        $user->deleteOrFail();
        Activity::query()->delete();

        $command = $this->artisan('user:restore');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'yes')
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'user_restored_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_restored_via_cli'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:restore');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'restored')
                ->count(),
            'Generischer user.restored-Eintrag darf neben dem Relabel nicht stehen bleiben.',
        );
    }

    public function testUserVerifyKeepsExistingEventCodeButGainsCliActor(): void
    {
        $user = User::factory()->unverified()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:verify');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'email_verified_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertCliActorPresent($activity, 'user:verify');
    }

    public function testUserResetPasswordWritesDedicatedAuditEntryWithCliActor(): void
    {
        $user = User::factory()->create([
            'email' => self::TARGET_EMAIL,
            'password' => Hash::make('old-password'),
        ]);
        Activity::query()->delete();

        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), self::PASSWORD)
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), self::PASSWORD)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->where('event', 'password_reset_via_cli')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Erwarteter password_reset_via_cli-Eintrag fehlt.');
        $this->assertSame(__('app.activity_password_reset_via_cli'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:reset-password');
        $this->assertNull(
            $activity->causer_id,
            'CLI-Reset darf keinen Causer tragen — `causedByAnonymous()` ist Pflicht.',
        );
    }

    public function testUserResetPasswordRemainsAnonymousEvenWhenAuthUserIsActing(): void
    {
        $actor = User::factory()->create(['email' => self::ACTOR_EMAIL]);
        $user = User::factory()->create([
            'email' => self::TARGET_EMAIL,
            'password' => Hash::make('old-password'),
        ]);
        Activity::query()->delete();

        $this->actingAs($actor);

        $command = $this->artisan('user:reset-password');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsQuestion(__('commands.reset_password.ask_password'), self::PASSWORD)
            ->expectsQuestion(__('commands.reset_password.ask_password_confirm'), self::PASSWORD)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'password_reset_via_cli')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
    }

    public function testRoleAssignKeepsExistingEventCodeButGainsCliActor(): void
    {
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('role:assign');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsChoice(__('commands.assign_role.ask_role'), 'admin', ['admin', 'member'])
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_attached')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertCliActorPresent($activity, 'role:assign');
        $properties = $activity->properties?->toArray() ?? [];
        $roles = $properties['roles'] ?? [];
        $this->assertIsArray($roles);
        $this->assertContains('admin', $roles);
    }

    /**
     * Schützt das gewählte Property-Layout: nested unter `cli_actor` (nicht
     * flach `cli_os_user`/`cli_hostname`/`cli_command`). Reports/Filter im
     * Audit-UI können den gesamten Akteur-Kontext als ein Objekt abgreifen,
     * und neue Felder lassen sich in einem JSON-Sub-Objekt erweitern, ohne
     * das Top-Level-Property-Schema zu vermüllen.
     */
    public function testCliActorShapeIsNestedNotFlat(): void
    {
        $user = User::factory()->unapproved()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->assertSuccessful()
            ->run();

        $activity = Activity::query()
            ->where('subject_id', $user->getKey())
            ->where('event', 'user_approved_via_cli')
            ->latest('id')
            ->firstOrFail();

        $properties = $activity->properties?->toArray() ?? [];

        $this->assertArrayHasKey('cli_actor', $properties);
        $this->assertIsArray($properties['cli_actor']);
        $this->assertArrayNotHasKey('cli_os_user', $properties);
        $this->assertArrayNotHasKey('cli_hostname', $properties);
        $this->assertArrayNotHasKey('cli_command', $properties);

        $actor = $properties['cli_actor'];
        $this->assertIsString($actor['os_user']);
        $this->assertNotSame('', $actor['os_user']);
        $this->assertIsString($actor['hostname']);
        $this->assertNotSame('', $actor['hostname']);
        $this->assertSame('user:approve', $actor['command']);
    }

    /**
     * Negativer Gegenbeweis: ohne aktiven Marker (außerhalb eines Artisan-
     * Commands) darf weder ein `cli_actor` angehängt noch ein generisches
     * Eloquent-Event umgelabelt werden — sonst wäre die Mechanik wertlos
     * und würde z. B. Web-Anlagen fälschlich als CLI-Vorgänge maskieren.
     */
    public function testActivityWithoutActiveContextHasNoCliActorAndNoRelabel(): void
    {
        ConsoleActorContext::clearStatically();
        Activity::query()->delete();

        $user = User::create([
            'name' => 'Direktanlage',
            'email' => 'direct@example.com',
            'password' => 'irrelevant',
        ]);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('created', $activity->event);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('cli_actor', $properties);
    }

    /**
     * Lifecycle-Garantie: nach `CommandFinished` muss der Marker wieder
     * leer sein, sonst würde ein nachfolgender Schreibvorgang im selben
     * PHP-Prozess (etwa eine Web-Request-Simulation in derselben Test-
     * Methode) fälschlich als CLI-Vorgang getarnt.
     */
    public function testContextIsClearedAfterCommandFinished(): void
    {
        User::factory()->unapproved()->create(['email' => self::TARGET_EMAIL]);

        $command = $this->artisan('user:approve');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->assertSuccessful()
            ->run();

        $context = $this->app->make(ConsoleActorContext::class);
        $this->assertFalse(
            $context->isActive(),
            'ConsoleActorContext hängt nach CommandFinished fest — Listener cleart nicht.',
        );

        Activity::query()->delete();
        ActivityFacade::useLog('user')->event('manual')->log('outside-cli');

        $entry = Activity::query()->where('event', 'manual')->firstOrFail();
        $properties = $entry->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey(
            'cli_actor',
            $properties,
            'Nach Command-Ende darf der nachfolgende Schreibvorgang kein cli_actor erben.',
        );
    }

    /**
     * Schützt die neuen Übersetzungs-Schlüssel: ohne diese Keys würde
     * Laravel den Maschinen-Code wörtlich als Description ablegen — die
     * Audit-UI würde dann `app.activity_user_created_via_cli` o. ä. als
     * Klartext anzeigen.
     */
    public function testTranslationKeysExistInAllLocales(): void
    {
        $events = [
            'user_created_via_cli',
            'user_approved_via_cli',
            'user_deleted_via_cli',
            'user_restored_via_cli',
            'password_reset_via_cli',
        ];

        foreach ($events as $event) {
            $key = 'app.activity_' . $event;

            foreach (['de', 'en'] as $locale) {
                $this->assertNotSame(
                    $key,
                    Lang::get($key, [], $locale),
                    sprintf("Übersetzungs-Schlüssel '%s' fehlt in Locale '%s'.", $key, $locale),
                );
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Statisches Marker-Feld zwischen Tests sauber halten — analog
        // SelfRegistrationContext-Cleanup im Schwester-Test.
        ConsoleActorContext::clearStatically();

        Role::findOrCreate('admin');
        Role::findOrCreate('member');
    }

    private function assertCliActorPresent(?Activity $activity, string $expectedCommand): void
    {
        $this->assertNotNull($activity);

        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('cli_actor', $properties);
        $this->assertIsArray($properties['cli_actor']);

        $actor = $properties['cli_actor'];
        $this->assertSame($expectedCommand, $actor['command'] ?? null);
        $this->assertNotSame('', $actor['os_user'] ?? '');
        $this->assertNotSame('', $actor['hostname'] ?? '');
    }
}
