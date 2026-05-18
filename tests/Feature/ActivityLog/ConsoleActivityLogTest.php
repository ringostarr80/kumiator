<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityLog;

use App\Models\User;
use App\Services\Console\ConsoleActorContext;
use App\Services\Console\Contracts\ConsoleActorContextContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\PendingCommand;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Support\FixedSecretTwoFactorProvider;
use Tests\TestCase;

/**
 * Validiert das CLI-Actor- & Event-Label-Verhalten für Artisan-Commands:
 *   - fachliche, channel-agnostische Event-Codes (`user_created`,
 *     `user_approved`, `user_deleted`, `user_restored`) statt generischer
 *     Eloquent-Events — das Labeling übernimmt `User::applyEventLabelToActivity`
 *     und greift sowohl für CLI- als auch für Web-/Seeder-Pfade,
 *   - Auth-Pfade (`password_reset` aus `UserPasswordResetter`,
 *     `email_verified` aus `UserEmailVerifier`) teilen sich den Event-Code
 *     mit ihren UI-Pendants; die Unterscheidung „CLI vs. Self-Service"
 *     steckt im Causer (CLI → null, UI → User) plus `cli_actor`-Property,
 *   - `cli_actor`-Property mit os_user/hostname/command (nested) auf jedem
 *     Eintrag, der während einer Command-Ausführung entsteht — DAS ist
 *     der eigentliche CLI-Marker; der Event-Code beschreibt nur den
 *     fachlichen Vorgang,
 *   - Causer-Anonymisierung: jeder Eintrag, der im CLI-Kontext entsteht,
 *     wird auf `causer_id`/`causer_type = null` reduziert — auch dann,
 *     wenn der Listener-Pfad (z. B. `LogTwoFactorActivityListener`) oder
 *     Spatie's Default-`CauserResolver` einen User-Causer setzen würde,
 *   - sauberes Lifecycle-Verhalten: kein Doppel-Logging, Kontext nach
 *     `CommandFinished` wieder leer.
 *
 * Mechanik: Ein Listener (`CaptureConsoleActorListener`) füllt den
 * `ConsoleActorContext`-Marker aus `Illuminate\Console\Events\CommandStarting`,
 * der zentrale `Activity::saving`-Hook im `AppServiceProvider` hängt das
 * `cli_actor`-Property an, und `CommandFinished` cleart wieder.
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
    private const string TEST_SECRET = 'JBSWY3DPEHPK3PXP';

    public function testUserCreateWritesUserCreatedWithCliActor(): void
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
            ->where('event', 'user_created')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Erwarteter user_created-Eintrag fehlt.');
        $this->assertSame(__('app.activity_user_created'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:create');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'created')
                ->count(),
            'Generischer user.created-Eintrag darf neben dem fachlichen Code nicht stehen bleiben.',
        );
    }

    public function testUserApproveWritesUserApprovedWithCliActor(): void
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
            ->where('event', 'user_approved')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_approved'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:approve');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'updated')
                ->count(),
            'Generischer user.updated-Eintrag darf neben dem fachlichen Code nicht stehen bleiben.',
        );
    }

    public function testUserDeleteWritesUserDeletedWithCliActor(): void
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
            ->where('event', 'user_deleted')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_deleted'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:delete');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'deleted')
                ->count(),
            'Generischer user.deleted-Eintrag darf neben dem fachlichen Code nicht stehen bleiben.',
        );
    }

    public function testUserRestoreWritesUserRestoredWithCliActor(): void
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
            ->where('event', 'user_restored')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(__('app.activity_user_restored'), $activity->description);
        $this->assertCliActorPresent($activity, 'user:restore');

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'user')
                ->where('event', 'restored')
                ->count(),
            'Generischer user.restored-Eintrag darf neben dem fachlichen Code nicht stehen bleiben.',
        );
    }

    public function testUserVerifyWritesEmailVerifiedWithCliActor(): void
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
            ->where('event', 'email_verified')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertNull(
            $activity->causer_id,
            'CLI-Verify darf keinen Causer tragen — `causedByAnonymous()` ist Pflicht.',
        );
        $this->assertCliActorPresent($activity, 'user:verify');
    }

    public function testUserResetPasswordWritesPasswordResetWithCliActor(): void
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
            ->where('event', 'password_reset')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Erwarteter password_reset-Eintrag fehlt.');
        $this->assertSame(__('app.activity_password_reset'), $activity->description);
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
            ->where('event', 'password_reset')
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
    }

    /**
     * Happy-Path-Symmetrie zum UI-Pfad: `user:enable-2fa` läuft seit
     * Punkt 3 der Roadmap über die Fortify-Actions, dispatcht also
     * `TwoFactorAuthenticationEnabled` + `TwoFactorAuthenticationConfirmed`
     * und produziert über den `LogTwoFactorActivityListener` zwei Einträge
     * (`2fa_enabled` + `2fa_confirmed`). Beide müssen im CLI-Kontext
     * anonymisiert werden — der Listener setzt zwar `causedBy($user)`,
     * der `ConsoleActorContext`-Hook (Schritt 3) überschreibt das aber.
     *
     * Schützt zwei Invarianten: (a) Symmetrie der Code-Paare auch im CLI,
     * (b) Anonymisierungs-Hook greift für Listener-Pfade — nicht nur für
     * direkte `causedByAnonymous()`-Aufrufe in den Services.
     */
    public function testUserEnableTwoFactorHappyPathWritesAnonymisedConfirmedAndEnabledWithCliActor(): void
    {
        $this->bindFixedSecretProvider();
        $validCode = $this->generateValidCode(self::TEST_SECRET);
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), $validCode)
            ->assertSuccessful()
            ->run();

        $enabled = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', '2fa_enabled')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($enabled, 'Erwarteter 2fa_enabled-Eintrag fehlt.');
        $this->assertCliActorPresent($enabled, 'user:enable-2fa');
        $this->assertNull(
            $enabled->causer_id,
            'CLI-2fa_enabled darf keinen Causer tragen — der Anonymisierungs-Hook muss greifen.',
        );
        $this->assertNull($enabled->causer_type);

        $confirmed = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', '2fa_confirmed')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($confirmed, 'Erwarteter 2fa_confirmed-Eintrag fehlt.');
        $this->assertCliActorPresent($confirmed, 'user:enable-2fa');
        $this->assertNull(
            $confirmed->causer_id,
            'CLI-2fa_confirmed darf keinen Causer tragen — der Anonymisierungs-Hook muss greifen.',
        );
        $this->assertNull($confirmed->causer_type);

        // Negativ-Gegenproben: weder ein Setup-Abort noch ein Disable
        // dürfen im Happy-Path entstehen — sonst wäre die Listener-Heuristik
        // (`wasChanged('two_factor_confirmed_at')`) im CLI gestört.
        $this->assertSame(
            0,
            Activity::query()->where('event', '2fa_setup_aborted')->count(),
            'Im Happy-Path darf kein 2fa_setup_aborted-Eintrag entstehen.',
        );
        $this->assertSame(
            0,
            Activity::query()->where('event', '2fa_disabled')->count(),
            'Im Happy-Path darf kein 2fa_disabled-Eintrag entstehen.',
        );
    }

    /**
     * Fehler-Pfad: ein ungültiger TOTP-Code führt zu `2fa_enabled` +
     * `2fa_setup_aborted` — der Fallback-`disableAction($user)` im
     * Command nutzt seit Schritt 3 der Roadmap die Fortify-Action, der
     * Listener erkennt am unveränderten `two_factor_confirmed_at` den
     * Setup-Abbruch und schreibt NICHT `2fa_disabled`. Auch hier muss
     * der CLI-Hook anonymisieren.
     */
    public function testUserEnableTwoFactorWithInvalidCodeWritesAnonymisedSetupAbortedWithCliActor(): void
    {
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $command = $this->artisan('user:enable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsQuestion(__('commands.enable_two_factor.ask_code'), '000000')
            ->assertFailed()
            ->run();

        $aborted = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', '2fa_setup_aborted')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($aborted, 'Erwarteter 2fa_setup_aborted-Eintrag fehlt.');
        $this->assertCliActorPresent($aborted, 'user:enable-2fa');
        $this->assertNull($aborted->causer_id, 'CLI-2fa_setup_aborted darf keinen Causer tragen.');
        $this->assertNull($aborted->causer_type);

        $enabled = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', '2fa_enabled')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($enabled, 'Auch im Fehler-Pfad muss 2fa_enabled vor dem Abort entstehen.');
        $this->assertCliActorPresent($enabled, 'user:enable-2fa');
        $this->assertNull($enabled->causer_id);

        // Negativ: kein Confirm (Code war ungültig) und kein Disable
        // (der Cleanup ist ein `setup_aborted`, kein `disabled`).
        $this->assertSame(
            0,
            Activity::query()->where('event', '2fa_confirmed')->count(),
            'Bei ungültigem Code darf kein 2fa_confirmed-Eintrag entstehen.',
        );
        $this->assertSame(
            0,
            Activity::query()->where('event', '2fa_disabled')->count(),
            'Setup-Abort darf NICHT als 2fa_disabled erscheinen — Listener-Heuristik wäre kaputt.',
        );
    }

    /**
     * `user:disable-2fa` läuft über `DisableTwoFactorAuthentication` und
     * dispatcht `TwoFactorAuthenticationDisabled`. Weil das Target bereits
     * bestätigtes 2FA hat (`two_factor_confirmed_at` ist gesetzt), erkennt
     * der Listener die echte Deaktivierung und schreibt `2fa_disabled`.
     * Im CLI-Kontext muss der Causer null sein und `cli_actor` mit
     * `command=user:disable-2fa` anhängen.
     */
    public function testUserDisableTwoFactorWritesAnonymisedTwoFactorDisabledWithCliActor(): void
    {
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        // Vor-Setup analog zum Schwester-Test in `DisableTwoFactorCommandTest`:
        // 2FA enable + `two_factor_confirmed_at` setzen, damit der Listener
        // im Disable-Pfad den `wasChanged`-Branch trifft (= `2fa_disabled`,
        // nicht `2fa_setup_aborted`). `force: true` umgeht die Re-Enable-Guard.
        $enableAction = app(EnableTwoFactorAuthentication::class);
        $enableAction($user, force: true);
        $user->forceFill(['two_factor_confirmed_at' => now()])->saveOrFail();
        // Den vom Setup geschriebenen `2fa_enabled`-Eintrag (causer=user,
        // ohne cli_actor — entstand außerhalb des CLI-Kontexts) abräumen,
        // damit die Assertion auf den disable-Eintrag nicht durch das
        // Setup-Rauschen verunreinigt wird.
        Activity::query()->delete();

        $command = $this->artisan('user:disable-2fa');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TARGET_EMAIL)
            ->expectsConfirmation(__('commands.disable_two_factor.confirm_disable'), 'yes')
            ->assertSuccessful()
            ->run();

        $disabled = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', '2fa_disabled')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($disabled, 'Erwarteter 2fa_disabled-Eintrag fehlt.');
        $this->assertCliActorPresent($disabled, 'user:disable-2fa');
        $this->assertNull(
            $disabled->causer_id,
            'CLI-2fa_disabled darf keinen Causer tragen — der Anonymisierungs-Hook muss greifen.',
        );
        $this->assertNull($disabled->causer_type);

        // Negativ: ein bestätigtes 2FA darf NICHT als `setup_aborted` enden —
        // sonst läge die Listener-Heuristik (`wasChanged`) falsch.
        $this->assertSame(
            0,
            Activity::query()->where('event', '2fa_setup_aborted')->count(),
            'Echte Deaktivierung darf nicht als 2fa_setup_aborted erscheinen.',
        );
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
     * Schwester-Test zum bestehenden `testRoleAssignKeepsExistingEventCodeButGainsCliActor`
     * für den **Observer-Pfad** (`RoleLifecycleObserver`): selbst wenn im CLI
     * eine Auth-Session aktiv ist (z. B. `actingAs($admin)` in künftigen
     * Impersonation-Skripten oder Permission-Check-Commands), darf der vom
     * Default-`CauserResolver` (über `Auth::user()`) gesetzte Causer nicht
     * stehen bleiben — der `ConsoleActorContext`-Hook aus Schritt 3 muss
     * anonymisieren. Der Observer selbst setzt keinen Causer; der Test
     * bewacht die End-Wirkung des Hooks im CLI-Pfad für Lifecycle-Audits.
     */
    public function testRoleCreateAnonymisesCauserEvenWhenAuthUserIsActing(): void
    {
        $actor = User::factory()->create(['email' => self::ACTOR_EMAIL]);
        Activity::query()->delete();

        $this->actingAs($actor);

        $command = $this->artisan('role:create');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.create_role.ask_name'), 'editor')
            ->assertSuccessful()
            ->run();

        $role = Role::findByName('editor');
        $activity = Activity::query()
            ->where('log_name', 'role')
            ->where('event', 'role_created')
            ->where('subject_type', $role->getMorphClass())
            ->where('subject_id', $role->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        // Gegenprobe: der CLI-Hook hat überhaupt gegriffen — ohne `cli_actor`
        // wäre der Null-Causer Zufall (etwa wenn `actingAs` aus irgendeinem
        // Grund still verworfen würde).
        $this->assertCliActorPresent($activity, 'role:create');
    }

    /**
     * Pendant zum Test darüber, aber für den **Listener-Pfad**
     * (`LogRoleChangeListener`): `role:assign` löst über
     * `User::syncRoles()` Spatie's `RoleAttachedEvent` aus, der Listener
     * schreibt `role_attached` ohne explizites `causedBy()` — der
     * Default-`CauserResolver` würde im `actingAs`-Kontext den Akteur
     * eintragen. Im CLI muss das anonymisiert werden.
     */
    public function testRoleAssignAnonymisesCauserEvenWhenAuthUserIsActing(): void
    {
        $actor = User::factory()->create(['email' => self::ACTOR_EMAIL]);
        $user = User::factory()->create(['email' => self::TARGET_EMAIL]);
        Activity::query()->delete();

        $this->actingAs($actor);

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
            ->firstOrFail();

        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        $this->assertCliActorPresent($activity, 'role:assign');
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
            ->where('event', 'user_approved')
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
     * Negativer Gegenbeweis: ohne aktiven CLI-Marker (außerhalb eines
     * Artisan-Commands) darf der Eintrag KEIN `cli_actor`-Property
     * tragen — sonst würde die Mechanik z. B. Web-Anlagen fälschlich
     * als CLI-Vorgänge maskieren.
     *
     * Hinweis: Der fachliche Event-Code (`user_created`) wird vom
     * channel-agnostischen `User::applyEventLabelToActivity`-Hook gesetzt
     * und ist daher AUCH außerhalb eines CLI-Kontexts gültig — die
     * Unterscheidung „CLI vs. Web/Seeder" läuft ausschließlich über das
     * Vorhandensein von `cli_actor`, nicht über den Event-Namen.
     */
    public function testActivityWithoutActiveContextHasNoCliActorButGetsFactualEventCode(): void
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

        $this->assertSame('user_created', $activity->event);
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayNotHasKey('cli_actor', $properties);
    }

    /**
     * Positivlauf der Causer-Anonymisierung: ein Activity-Eintrag, der im
     * aktiven CLI-Kontext explizit `causedBy($user)` schreibt, landet mit
     * `causer_id`/`causer_type = null` in der DB. Das ist der Mechanismus,
     * der Listener-Pfaden (z. B. `LogTwoFactorActivityListener`, der den
     * User explizit als Causer setzt) im CLI-Kontext die falsche
     * Self-Service-Attribution nimmt.
     */
    public function testCausalUserIsAnonymisedWhileCliContextIsActive(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        $context = $this->app->make(ConsoleActorContextContract::class);
        $context->activate([
            'os_user' => 'test-os-user',
            'hostname' => 'test-host',
            'command' => 'synthetic:command',
        ]);

        try {
            ActivityFacade::useLog('synthetic-log')
                ->event('synthetic-event')
                ->causedBy($user)
                ->performedOn($user)
                ->log('synthetic');
        } finally {
            $context->clear();
        }

        $activity = Activity::query()
            ->where('log_name', 'synthetic-log')
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($activity->causer_id);
        $this->assertNull($activity->causer_type);
        // Gegenprobe: dass der Hook überhaupt gegriffen hat, sieht man
        // am `cli_actor`-Property — sonst wäre der Causer-Reset Zufall
        // (etwa weil der CauserResolver in der Test-Session gar keinen
        // Auth-User hatte).
        $properties = $activity->properties?->toArray() ?? [];
        $this->assertArrayHasKey('cli_actor', $properties);
    }

    /**
     * Negativer Gegenbeweis zum Anonymisierungs-Test darüber: ohne aktiven
     * CLI-Kontext bleibt ein explizit gesetzter Causer stehen — der Hook
     * darf NICHT pauschal alle Web-Pfade anonymisieren, sonst wäre die
     * Self-Service-Spur (Causer = handelnder User) im UI-Pfad zerstört.
     */
    public function testCausalUserSurvivesOutsideCliContext(): void
    {
        ConsoleActorContext::clearStatically();
        $user = User::factory()->create();
        Activity::query()->delete();

        ActivityFacade::useLog('synthetic-log')
            ->event('synthetic-event')
            ->causedBy($user)
            ->performedOn($user)
            ->log('synthetic');

        $activity = Activity::query()
            ->where('log_name', 'synthetic-log')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->getMorphClass(), $activity->causer_type);
        $this->assertSame($user->getKey(), $activity->causer_id);
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
     * Schützt die Übersetzungs-Schlüssel: ohne diese Keys würde Laravel den
     * Maschinen-Code wörtlich als Description ablegen — die Audit-UI würde
     * dann `app.activity_user_created` o. ä. als Klartext anzeigen.
     *
     * Geprüft werden die fachlichen Codes vom User-Lifecycle-Hook sowie
     * die channel-agnostischen Auth-Codes, die der CLI-Pfad mit dem
     * UI-Pfad teilt (`password_reset`, `email_verified`).
     */
    public function testTranslationKeysExistInAllLocales(): void
    {
        $events = [
            'user_created',
            'user_approved',
            'user_renamed',
            'user_deleted',
            'user_restored',
            'password_reset',
            'email_verified',
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

    /**
     * Bindet den Fortify-2FA-Provider auf eine fixe Secret-Quelle, damit der
     * Test einen validen TOTP-Code vorab erzeugen kann. Analog zum Pattern in
     * `EnableTwoFactorCommandTest` — bewusst keine Trait-Auslagerung, weil
     * derzeit nur diese eine Test-Klasse die CLI-Mechanik gegen 2FA prüft.
     */
    private function bindFixedSecretProvider(): void
    {
        $this->app->instance(
            TwoFactorAuthenticationProvider::class,
            new FixedSecretTwoFactorProvider(new Google2FA(), self::TEST_SECRET),
        );
    }

    private function generateValidCode(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }
}
