<?php

declare(strict_types=1);

namespace Tests\Feature\SoftDeletes;

use App\Actions\Jetstream\DeleteUser;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\User\Contracts\UserHardDeleterContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Spatie\Permission\Models\Permission;
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
     * Tokens und Passkeys fallen im Hard-Delete bewusst per Mass-`delete()` am
     * Query-Builder — also an den Model-Events vorbei. Ein `each->deleteOrFail()`
     * (im Soft-Delete genau richtig) ließe den `LogsActivity`-Trait pro Credential
     * einen `passkey_removed`-Eintrag schreiben, dessen Subject die Credential ist
     * und nicht der User: Der DSGVO-Purge greift daran vorbei, der Eintrag bliebe
     * als Verweis auf das gelöschte Konto liegen. Die Eigenschaft hängt an einem
     * einzigen Methodenaufruf und ist von außen unsichtbar — deshalb wird hier
     * festgehalten, was am Ende in der Tabelle stehen darf: nichts außer dem
     * anonymen Audit-Eintrag des Vorgangs selbst.
     */
    public function testHardDeleteWritesNoActivityEntriesForRemovedTokensAndPasskeys(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        PasskeyCredential::factory()->for($user)->create();
        $user->createToken('test');
        Activity::query()->delete();

        app(DeleteUser::class)->delete($user);

        $entries = Activity::query()->get();

        $this->assertCount(
            1,
            $entries,
            'Der Hard-Delete darf außer seinem eigenen Audit-Eintrag nichts hinterlassen — '
            . 'Einträge über gelöschte Tokens/Passkeys überleben den Purge.',
        );
        $entry = $entries->first();
        $this->assertNotNull($entry);
        $this->assertSame(ActivityEvent::ACCOUNT_SELF_DELETED->value, $entry->event);
        $this->assertNull($entry->causer_id);
    }

    /**
     * Direkt-Permission-Pivots verschwinden — wie die Rollen-Pivots — allein
     * durch Spaties `deleting`-Hook, der beim Force-Delete still (ohne Event)
     * detacht. Ändert ein Spatie-Update dieses Verhalten, blieben
     * Berechtigungen als verwaiste Pivot-Zeilen zurück (DSGVO-relevant) und
     * könnten bei ID-Wiederverwendung wieder aufleben — dieser Test schlägt
     * dann an.
     */
    public function testSelfDeleteHardDeletesDirectPermissionPivots(): void
    {
        $user = User::factory()->create();
        Permission::findOrCreate('activity-log.view');
        $user->givePermissionTo('activity-log.view');

        $this->assertSame(
            1,
            DB::table('model_has_permissions')
                ->where('model_type', $user->getMorphClass())
                ->where('model_id', $user->getKey())
                ->count(),
        );

        app(DeleteUser::class)->delete($user);

        $this->assertSame(
            0,
            DB::table('model_has_permissions')
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
     * Der administrative Lösch-Weg ist zweistufig: `user:delete` (Soft) entfernt
     * die Credentials bereits hart, `user:force-delete` löscht das Konto später
     * endgültig. Zu diesem Zeitpunkt ist ein Passkey-Log-Eintrag über nichts mehr
     * dem User zuzuordnen — die Credential-Zeile ist weg, und der CLI-Pfad hat den
     * Causer anonymisiert. Ein Purge kommt hier also grundsätzlich zu spät; der
     * vom Nutzer vergebene Name darf deshalb schon beim Schreiben draußen bleiben.
     *
     * Dieser Test hält das Schutzziel selbst fest (kein Personenbezug im Log nach
     * der Löschung), nicht den Mechanismus — er bliebe auch dann gültig, wenn der
     * Name künftig auf einem anderen Weg als über den Attribut-Diff ins Log geriete.
     */
    public function testAdminDeletePathLeavesNoPasskeyNameInActivityLog(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        PasskeyCredential::factory()->for($user)->create(['name' => 'iPhone von Erika']);

        app(UserSoftDeleterContract::class)->softDelete($user);
        app(UserHardDeleterContract::class)->forceDelete($user, ActivityEvent::ACCOUNT_ADMIN_FORCE_DELETED);

        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString(
                'Erika',
                json_encode($entry->toArray(), JSON_THROW_ON_ERROR),
                'Nach dem administrativen Hard-Delete darf kein Log-Eintrag mehr den '
                . 'Passkey-Namen des gelöschten Users tragen.',
            );
        }
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
     * Causer-Referenzen des gelöschten Users werden anonymisiert, nicht gelöscht:
     * Wenn er als handelnder Akteur an einem fremden Subject auftrat (z. B. eine
     * Rollenzuweisung an eine andere Person), ist der Eintrag ein
     * sicherheitsrelevanter Beleg (Art. 5(2)/32). Der Beleg bleibt erhalten, nur
     * `causer_type`/`causer_id` fallen weg — die einzige Personenspur des Causers,
     * denn seine PII steht nie in `properties`.
     */
    public function testSelfDeleteAnonymizesActivityLogEntriesWithUserAsCauser(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        Role::findOrCreate('member');

        $this->actingAs($actor);
        $other->assignRole('member');

        $causedEntry = Activity::query()
            ->where('causer_type', $actor->getMorphClass())
            ->where('causer_id', $actor->getKey())
            ->firstOrFail();

        app(DeleteUser::class)->delete($actor);

        // Zeile existiert weiter (nicht gelöscht), Causer ist gekappt (anonymisiert).
        $this->assertDatabaseHas($causedEntry->getTable(), [
            $causedEntry->getKeyName() => $causedEntry->getKey(),
            'causer_type' => null,
            'causer_id' => null,
        ]);
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

    /**
     * Regression: Der Foto-Aufräumschritt nach dem Commit darf den hart
     * gelöschten User nicht wieder anlegen. Jetstreams `deleteProfilePhoto()`
     * endet mit `->save()`; auf dem per `forceDelete()` bereits entfernten Model
     * (`exists=false`) ist das ein INSERT — der User käme samt E-Mail und
     * Passwort-Hash mit `deleted_at=null` als aktiver Account zurück und
     * unterliefe die DSGVO-Löschung. Ohne Profilfoto greift der Early-Return im
     * Trait, weshalb die übrigen Lösch-Tests den Pfad nicht treffen.
     */
    public function testSelfDeleteWithProfilePhotoDoesNotResurrectUser(): void
    {
        Config::set('jetstream.profile_photo_disk', $disk = 'public');
        Storage::fake($disk);

        $user = User::factory()->create();
        Storage::disk($disk)->put($photoPath = 'profile-photos/avatar.jpg', 'binary');
        $user->forceFill(['profile_photo_path' => $photoPath])->saveQuietly();

        $userId = $user->getKey();

        app(DeleteUser::class)->delete($user);

        $this->assertNull(
            User::query()->withTrashed()->find($userId),
            'Der hart gelöschte User darf durch das Foto-Aufräumen nicht wieder in der DB auftauchen.',
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

    /**
     * Regressionsschutz: Liegt `session.connection` auf einer anderen
     * Connection als die Lösch-Transaktion, darf ein Fehler in der Transaktion
     * die Session-Zeile NICHT bereits gelöscht haben — sonst wäre der Nutzer
     * ausgeloggt, das Konto aber noch aktiv. Beide Deleter löschen Sessions
     * deshalb erst nach dem Commit.
     */
    public function testSoftDeleteKeepsSessionWhenTransactionRollsBackOnSeparateConnection(): void
    {
        $user = User::factory()->create();
        $this->prepareSeparateSessionStoreFor($user);

        User::deleting(static function (): void {
            throw new RuntimeException('Lösch-Transaktion abgebrochen');
        });

        try {
            app(UserSoftDeleterContract::class)->softDelete($user);
            $this->fail('Erwartete Ausnahme aus der Lösch-Transaktion blieb aus.');
        } catch (RuntimeException) {
            // Erwartet: der `deleting`-Hook bricht die Transaktion ab.
        }

        $this->assertSame(
            1,
            DB::connection('session_store')->table('sessions')->where('user_id', $user->getKey())->count(),
            'Die Session auf der separaten Connection muss den Transaktions-Rollback überleben.',
        );
    }

    public function testHardDeleteKeepsSessionWhenTransactionRollsBackOnSeparateConnection(): void
    {
        $user = User::factory()->create();
        $this->prepareSeparateSessionStoreFor($user);

        User::deleting(static function (): void {
            throw new RuntimeException('Lösch-Transaktion abgebrochen');
        });

        try {
            app(UserHardDeleterContract::class)->forceDelete($user, ActivityEvent::ACCOUNT_SELF_DELETED);
            $this->fail('Erwartete Ausnahme aus der Lösch-Transaktion blieb aus.');
        } catch (RuntimeException) {
            // Erwartet: der `deleting`-Hook bricht die Transaktion ab.
        }

        $this->assertSame(
            1,
            DB::connection('session_store')->table('sessions')->where('user_id', $user->getKey())->count(),
            'Die Session auf der separaten Connection muss den Transaktions-Rollback überleben.',
        );
    }

    /**
     * Sessions auf eine eigene, physisch getrennte Connection (zweites
     * In-Memory-SQLite) legen und eine Zeile für $user einsetzen — so liegt das
     * Session-Delete außerhalb der Default-Connection-Transaktion der Deleter.
     */
    private function prepareSeparateSessionStoreFor(User $user): void
    {
        Config::set('database.connections.session_store', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        Config::set('session.driver', 'database');
        Config::set('session.connection', 'session_store');

        Schema::connection('session_store')->create('sessions', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        DB::connection('session_store')->table('sessions')->insert([
            'id' => 'sess-on-store',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }
}
