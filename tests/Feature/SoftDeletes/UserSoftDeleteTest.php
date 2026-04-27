<?php

declare(strict_types=1);

namespace Tests\Feature\SoftDeletes;

use App\Actions\Jetstream\DeleteUser;
use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const string EMAIL_TAKEN = 'taken@example.com';

    public function testSoftDeletedUserIsExcludedFromDefaultQueries(): void
    {
        $user = User::factory()->create();
        $user->deleteOrFail();

        $this->assertNull(User::query()->find($user->getKey()));
        $this->assertNotNull(User::query()->withTrashed()->find($user->getKey()));
        $this->assertTrue($user->trashed());
    }

    /**
     * Sicherheitsrelevante Invariante: Der PasskeyCredential->user-Zugriff MUSS
     * für soft-deleted User `null` liefern. Auf genau diese Garantie verlässt sich
     * `PasskeyAuthenticationService::verify()`, um den Passkey-Login für
     * administrativ gelöschte User zu blockieren. Würde jemand die Relation
     * später auf `->withTrashed()` umbauen, wäre der Login-Schutz still und
     * heimlich gebrochen — dieser Test schlägt dann an.
     */
    public function testSoftDeletedUserIsUnreachableViaPasskeyCredentialRelation(): void
    {
        $user = User::factory()->create();
        $credential = PasskeyCredential::factory()->for($user)->create();

        $user->deleteOrFail();

        $fresh = PasskeyCredential::query()->whereKey($credential->getKey())->firstOrFail();

        $this->assertNull($fresh->user);
    }

    public function testSoftDeletedUserCannotLogIn(): void
    {
        $user = User::factory()->create(['email' => 'deleted@example.com']);
        $user->deleteOrFail();

        $response = $this->post('/login', [
            'email' => 'deleted@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors();
    }

    /**
     * Bewusste Festlegung: Die Email einer soft-deleted Person bleibt belegt.
     *
     * Der Wiedereintritt eines Mitglieds soll über `restore()` laufen, damit die
     * fachliche Verknüpfung (Beitragshistorie, Activity-Log-Subjects, Rollen)
     * erhalten bleibt — *nicht* über eine Neuregistrierung mit derselben Email.
     * Wer dieses Verhalten ändern möchte, muss zuerst klären, wie Restore-Pfad
     * und Re-Registrierung sauber koexistieren (Pseudonymisierung der alten
     * Email beim Soft-Delete? Welche Email gilt nach Restore?).
     */
    public function testSoftDeletedEmailCannotBeReusedForNewRegistration(): void
    {
        $user = User::factory()->create(['email' => self::EMAIL_TAKEN]);
        $user->deleteOrFail();

        $response = $this->post('/register', [
            'name' => 'Neu',
            'email' => self::EMAIL_TAKEN,
            'password' => 'Password!1234',
            'password_confirmation' => 'Password!1234',
            'terms' => true,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, User::query()->withTrashed()->where('email', self::EMAIL_TAKEN)->count());
    }

    public function testSoftDeletedUserCanBeRestored(): void
    {
        $user = User::factory()->create();
        $user->deleteOrFail();
        $this->assertTrue($user->trashed());

        $user->restore();

        $restored = $user->fresh();
        $this->assertNotNull($restored);
        $this->assertFalse($restored->trashed());
    }

    public function testSelfDeleteHardDeletesUserIncludingTokensPasskeysAndSessions(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        Role::findOrCreate('member');
        $user->assignRole('member');
        PasskeyCredential::factory()->create(['user_id' => $user->getKey()]);
        $user->createToken('test');
        DB::table('sessions')->insert([
            'id' => 'test-session-id',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->assertSame(
            1,
            DB::table('model_has_roles')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->count(),
        );

        app(DeleteUser::class)->delete($user);

        $this->assertSame(0, User::query()->withTrashed()->where('id', $user->getKey())->count());
        $this->assertSame(0, PasskeyCredential::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
        $this->assertSame(
            0,
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->count(),
        );
        // Spatie\Permission räumt Rollen-Pivots beim Hard-Delete via Eloquent-
        // `deleting`-Event auf. Sollte ein zukünftiges Spatie-Update dieses
        // Verhalten ändern oder der Listener ausgehängt werden, wäre der hart
        // gelöschte User unauffindbar, seine Rollen-Zuweisungen würden aber als
        // verwaiste Pivot-Zeilen weiterleben — DSGVO-relevant.
        $this->assertSame(
            0,
            DB::table('model_has_roles')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->count(),
        );
    }

    /**
     * Komplement zum Hard-Delete-Test: Beim Soft-Delete dürfen Rollen-Pivots
     * NICHT gelöscht werden — sonst wäre ein späteres `restore()` ein stiller
     * Privilegien-Verlust für den wiederhergestellten User.
     */
    public function testSoftDeleteKeepsRoleAssignmentsForLaterRestore(): void
    {
        $user = User::factory()->create();
        Role::findOrCreate('member');
        $user->assignRole('member');

        $user->deleteOrFail();

        $this->assertSame(
            1,
            DB::table('model_has_roles')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->count(),
        );
    }

    /**
     * DSGVO-Symmetrie zum Token-/Passkey-/Session-Bypass: Nach dem Self-Delete
     * darf in `activity_log` kein Eintrag mehr existieren, der den gelöschten
     * User als Subject referenziert — weder durch Alt-Einträge (z. B. das
     * Profil-Update-Log einer früheren Namensänderung) noch durch finale
     * Einträge, die `forceDelete()` selbst auslösen würde (`event=deleted`,
     * `event=role_detached` durch `LogRoleChangeListener`).
     */
    public function testSelfDeletePurgesActivityLogEntriesWithUserAsSubject(): void
    {
        $user = User::factory()->create(['name' => 'Vor Löschung']);
        Role::findOrCreate('member');
        $user->assignRole('member');
        $user->updateOrFail(['name' => 'Nach Umbenennung']);

        $this->assertGreaterThan(
            0,
            Activity::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->count(),
            'Setup-Annahme verletzt: es sollten Subject-Einträge existieren, sonst testet die Assertion nichts.',
        );

        app(DeleteUser::class)->delete($user);

        $this->assertSame(
            0,
            Activity::query()
                ->where('subject_type', $user->getMorphClass())
                ->where('subject_id', $user->getKey())
                ->count(),
        );
    }

    /**
     * Auch Causer-Referenzen müssen weg: Wenn der zu löschende User vorher
     * selbst als handelnder Akteur in Activity-Einträgen aufgetaucht ist
     * (z. B. weil er einer anderen Person eine Rolle zugewiesen hat),
     * verbleiben sonst seine ID und ggf. der Name in `properties`.
     */
    public function testSelfDeletePurgesActivityLogEntriesWithUserAsCauser(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        Role::findOrCreate('member');

        $this->actingAs($actor);
        $other->assignRole('member');

        $this->assertGreaterThan(
            0,
            Activity::query()
                ->where('causer_type', $actor->getMorphClass())
                ->where('causer_id', $actor->getKey())
                ->count(),
            'Setup-Annahme verletzt: es sollten Causer-Einträge auf $actor existieren.',
        );

        app(DeleteUser::class)->delete($actor);

        $this->assertSame(
            0,
            Activity::query()
                ->where('causer_type', $actor->getMorphClass())
                ->where('causer_id', $actor->getKey())
                ->count(),
        );
    }

    /**
     * Negativ-Test: Activity-Einträge **anderer** User dürfen vom Self-Delete
     * NICHT angefasst werden. Sonst wäre der Purge ein versehentliches
     * Massen-Löschen fremder Audit-Daten.
     */
    public function testSelfDeleteLeavesActivityLogEntriesOfOtherUsersUntouched(): void
    {
        $deleter = User::factory()->create();
        $bystander = User::factory()->create(['name' => 'Bystander']);
        $bystander->updateOrFail(['name' => 'Bystander Renamed']);

        $bystanderActivityCountBefore = Activity::query()
            ->where('subject_type', $bystander->getMorphClass())
            ->where('subject_id', $bystander->getKey())
            ->count();
        $this->assertGreaterThan(0, $bystanderActivityCountBefore);

        app(DeleteUser::class)->delete($deleter);

        $this->assertSame(
            $bystanderActivityCountBefore,
            Activity::query()
                ->where('subject_type', $bystander->getMorphClass())
                ->where('subject_id', $bystander->getKey())
                ->count(),
        );
    }

    public function testConsoleDeleteCommandSoftDeletesUserAndPurgesSessionsPasskeysAndTokens(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['email' => 'admin-delete@example.com']);
        PasskeyCredential::factory()->for($user)->count(2)->create();
        $user->createToken('admin-delete-token');
        DB::table('sessions')->insert([
            'id' => 'admin-session-id',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $command = $this->artisan('user:delete');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'admin-delete@example.com')
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertNull(User::query()->find($user->getKey()));
        $this->assertNotNull(User::query()->withTrashed()->find($user->getKey()));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
        $this->assertSame(0, PasskeyCredential::query()->where('user_id', $user->getKey())->count());
        $this->assertSame(
            0,
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->count(),
        );
    }
}
