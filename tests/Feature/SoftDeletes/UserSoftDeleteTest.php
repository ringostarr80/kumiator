<?php

declare(strict_types=1);

namespace Tests\Feature\SoftDeletes;

use App\Actions\Jetstream\DeleteUser;
use App\Enums\ActivityChannel;
use App\Enums\ActivityEvent;
use App\Enums\EmailChangeCancellationReason;
use App\Models\Activity;
use App\Models\PasskeyCredential;
use App\Models\User;
use App\Notifications\PasskeyChangedNotification;
use App\Services\Audit\AuditEmailHasher;
use App\Services\Auth\Contracts\LoginMethodChangerContract;
use App\Services\User\Contracts\UserHardDeleterContract;
use App\Services\User\Contracts\UserSoftDeleterContract;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsPendingEmailChange;
use Tests\TestCase;

final class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPendingEmailChange;

    private const string EMAIL_TAKEN = 'taken@example.com';
    private const string PENDING_EMAIL = 'pending@example.com';

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
     *
     * Die Registrierung antwortet trotzdem wie für eine freie Adresse, und die
     * ausgetretene Person bekommt keine Mail.
     */
    public function testSoftDeletedEmailCannotBeReusedForNewRegistration(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => self::EMAIL_TAKEN]);
        $user->deleteOrFail();

        $response = $this->post('/register', [
            'name' => 'Neu',
            'email' => self::EMAIL_TAKEN,
            'terms' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, User::query()->withTrashed()->where('email', self::EMAIL_TAKEN)->count());
        Notification::assertNothingSent();
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

    public function testSelfDeleteHardDeletesUserIncludingPasskeysAndSessions(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        Role::findOrCreate('member');
        $user->assignRole('member');
        PasskeyCredential::factory()->create(['user_id' => $user->getKey()]);
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
     * Passkeys fallen im Hard-Delete bewusst per Mass-`delete()` am
     * Query-Builder — also an den Model-Events vorbei. Ein `each->deleteOrFail()`
     * (im Soft-Delete genau richtig) ließe den `LogsActivity`-Trait pro Credential
     * einen `passkey_removed`-Eintrag schreiben, dessen Subject die Credential ist
     * und nicht der User: Der DSGVO-Purge greift daran vorbei, der Eintrag bliebe
     * als Verweis auf das gelöschte Konto liegen. Die Eigenschaft hängt an einem
     * einzigen Methodenaufruf und ist von außen unsichtbar — deshalb wird hier
     * festgehalten, was am Ende in der Tabelle stehen darf: nichts außer dem
     * anonymen Audit-Eintrag des Vorgangs selbst.
     */
    public function testHardDeleteWritesNoActivityEntriesForRemovedPasskeys(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        app(DeleteUser::class)->delete($user);

        $entries = Activity::query()->get();

        $this->assertCount(
            1,
            $entries,
            'Der Hard-Delete darf außer seinem eigenen Audit-Eintrag nichts hinterlassen — '
            . 'Einträge über gelöschte Passkeys überleben den Purge.',
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
     * Die Passkeys des Kontos fallen hier über Model-Events. Die Hinweismail über
     * einen entfernten Passkey hängt bewusst nicht daran — sonst bekäme ein
     * Konto, das gerade gelöscht wird, pro Passkey eine Mail.
     */
    public function testSoftDeleteSendsNoNoticeForTheRemovedPasskeys(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        app(UserSoftDeleterContract::class)->softDelete($user);

        Notification::assertNotSentTo($user, PasskeyChangedNotification::class);
    }

    /**
     * Anders als der Hard-Delete behält der Soft-Delete die Historie des Kontos,
     * und dazu gehört, welche Passkeys mit dem Löschen gefallen sind. Ob die
     * Einträge entstehen, hängt allein daran, dass die Passkeys einzeln über das
     * Model fallen und nicht per Query.
     */
    public function testSoftDeleteRecordsEachRemovedPasskey(): void
    {
        $user = User::factory()->create();
        $credentials = PasskeyCredential::factory()->for($user)->count(2)->create();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $removed = Activity::query()
            ->where('log_name', ActivityChannel::PASSKEY->value)
            ->where('event', ActivityEvent::PASSKEY_REMOVED->value)
            ->pluck('subject_id')
            ->all();

        $this->assertEqualsCanonicalizing($credentials->modelKeys(), $removed);
    }

    /**
     * DSGVO-Symmetrie zum Passkey-/Session-Bypass: Nach dem Self-Delete
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

    /**
     * Das Foto ist personenbezogen (DSGVO Art. 17) und liegt außerhalb der
     * Datenbank: Weder Fremdschlüssel noch Transaktion räumen es mit ab.
     */
    public function testSelfDeleteRemovesTheProfilePhoto(): void
    {
        Config::set('jetstream.profile_photo_disk', $disk = 'public');
        Storage::fake($disk);

        $user = User::factory()->create();
        Storage::disk($disk)->put($photoPath = 'profile-photos/avatar.jpg', 'binary');
        $user->forceFill(['profile_photo_path' => $photoPath])->saveQuietly();

        app(DeleteUser::class)->delete($user);

        Storage::disk($disk)->assertMissing($photoPath);
    }

    public function testConsoleDeleteCommandSoftDeletesUserAndPurgesSessionsAndPasskeys(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['email' => 'admin-delete@example.com']);
        PasskeyCredential::factory()->for($user)->count(2)->create();
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
    }

    /**
     * Der abgeschaltete Passwort-Login setzt einen Passkey voraus, und die nimmt
     * dieser Pfad alle mit. Bliebe die Abschaltung stehen, käme das wiederhergestellte
     * Konto über keinen Weg mehr hinein: Das Passwort weist der Login ab, ein
     * Passkey ist nicht mehr da, und den Link zum Zurücksetzen bekommen solche
     * Konten nicht geschickt.
     */
    public function testSoftDeleteReopensPasswordLoginForALaterRestore(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $trashed->restore();

        $this->assertFalse($trashed->isPasswordLoginDisabled());
    }

    /**
     * Die Instanz des Aufrufers kann älter sein als der Aufruf — schaltet der
     * Nutzer den Passwort-Login in der Zwischenzeit ab, sieht sie ihn noch offen.
     * Entschieden werden muss auf der gesperrten Zeile, sonst überlebt die
     * Abschaltung den Verlust aller Passkeys.
     */
    public function testSoftDeleteReopensAPasswordLoginDisabledAfterTheUserWasLoaded(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        app(LoginMethodChangerContract::class)->disablePasswordLogin($user);

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $trashed->restore();

        $this->assertFalse($trashed->isPasswordLoginDisabled());
    }

    /**
     * Die Spalte steht nicht in der `logOnly`-Allowlist des Users — ohne eigenen
     * Eintrag spränge der Passwort-Login spurlos wieder auf.
     */
    public function testSoftDeleteRecordsTheReopenedPasswordLogin(): void
    {
        $user = User::factory()->create(['password_login_disabled_at' => now()]);
        PasskeyCredential::factory()->for($user)->create();
        Activity::query()->delete();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $entry = Activity::query()
            ->where('log_name', ActivityChannel::AUTH->value)
            ->where('event', ActivityEvent::PASSWORD_LOGIN_ENABLED->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->causer_id);
        $this->assertSame($user->getKey(), $entry->subject_id);
    }

    /**
     * Abgeschaltet wird der Passwort-Login, wenn jemand dem Passwort nicht mehr
     * traut. Der wieder geöffnete Zugang dürfte diese Aussage sonst still
     * zurücknehmen: Nach dem Restore ließe genau das verdächtige Passwort wieder
     * hinein, ohne dass es je jemand neu gesetzt hat.
     */
    public function testSoftDeleteInvalidatesThePasswordItReopensTheLoginFor(): void
    {
        $user = User::factory()->create([
            'password' => 'verdaechtiges-passwort',
            'password_login_disabled_at' => now(),
        ]);
        PasskeyCredential::factory()->for($user)->create();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();

        $this->assertFalse(Hash::check('verdaechtiges-passwort', $trashed->password));
    }

    /**
     * bcrypt dauert. Unter SQLite hielte die Transaktion dabei schon die
     * Schreibsperre auf die ganze Datenbank, und jede andere Transaktion, die
     * erst liest und dann schreibt, scheiterte in dieser Zeit sofort.
     */
    public function testTheReplacementPasswordIsHashedOutsideTheTransaction(): void
    {
        $user = User::factory()->create();
        $outside = DB::transactionLevel();

        // Das Ersatzpasswort entsteht aus `Str::random()`, unmittelbar vor dem Hash.
        $levels = [];
        Str::createRandomStringsUsing(static function (int $length) use (&$levels): string {
            $levels[] = DB::transactionLevel();

            return str_repeat('x', $length);
        });

        app(UserSoftDeleterContract::class)->softDelete($user);
        app(UserSoftDeleterContract::class)->restore($user);

        $this->assertSame([$outside, $outside], $levels);
    }

    /**
     * Zeitstempel sind sekundengenau. Fällt die Abschaltung in dieselbe Sekunde
     * wie das Löschen, gälte auch das Ersatzpasswort als verdächtig und würde in
     * der Transaktion ein zweites Mal ersetzt.
     */
    public function testSoftDeleteHashesOnceWhenThePasswordLoginWasDisabledInTheSameSecond(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 10:00:00'));
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        $this->travelTo(Carbon::parse('2026-01-02 10:00:00'));
        $user->password_login_disabled_at = now();
        $user->saveOrFail();
        $outside = DB::transactionLevel();

        $levels = [];
        Str::createRandomStringsUsing(static function (int $length) use (&$levels): string {
            $levels[] = DB::transactionLevel();

            return str_repeat('x', $length);
        });

        app(UserSoftDeleterContract::class)->softDelete($user);

        $this->assertSame([$outside], $levels);
    }

    /** Ein Konto, das seinen Passwort-Login noch nutzt, hat keine Abschaltung zu verlieren. */
    public function testSoftDeleteLeavesAnOpenSwitchAlone(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $this->assertSame(
            0,
            Activity::query()->where('event', ActivityEvent::PASSWORD_LOGIN_ENABLED->value)->count(),
        );
    }

    /**
     * Der Pfad nimmt jedes Zugriffsmittel mit, das ein `restore()` sonst wieder
     * scharf schaltete. Ein vorher ausgelöster Link zum Zurücksetzen überlebte als
     * einziges — und träfe auf ein Konto, dessen Passwort dieser Pfad gerade
     * verworfen hat.
     */
    public function testSoftDeleteInvalidatesAnOpenPasswordResetLink(): void
    {
        $user = User::factory()->create();

        $token = Password::broker()->createToken($user);

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $trashed->restore();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $trashed->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $trashed->refresh();

        $this->assertFalse(Hash::check('brand-new-password', $trashed->password));
    }

    /**
     * Nach einem `restore()` führt nur noch der Link zum Zurücksetzen ins Konto,
     * und der geht an die hinterlegte Adresse. Ein vorher beantragter Wechsel
     * gälte sonst wieder und gäbe mit der Adresse das Konto dem, der den
     * Bestätigungslink hat.
     */
    public function testSoftDeleteCancelsAnOpenEmailChangeForALaterRestore(): void
    {
        $user = User::factory()->create();
        $email = $user->email;
        $confirmToken = $this->seedPendingChange($user, self::PENDING_EMAIL)['confirm'];

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue($trashed->restore());

        $this->post(route('email.change.confirm.perform', ['token' => $confirmToken]))
            ->assertViewIs('auth.email-change-invalid');

        $this->assertSame($email, $trashed->refresh()->email);
    }

    /**
     * Wie bei jedem anderen Ende eines offenen Wechsels hält ein eigener Eintrag
     * fest, warum er endete. Sonst bliebe der Antrag im Log offen.
     */
    public function testSoftDeleteRecordsTheCancelledEmailChange(): void
    {
        $user = User::factory()->create();
        $this->seedPendingChange($user, self::PENDING_EMAIL);
        Activity::query()->delete();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $entry = Activity::query()
            ->where('log_name', ActivityChannel::AUTH->value)
            ->where('event', ActivityEvent::EMAIL_CHANGE_CANCELLED->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->causer_id);
        $this->assertSame($user->getKey(), $entry->subject_id);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame(
            EmailChangeCancellationReason::ACCOUNT_SOFT_DELETED->value,
            $properties['cancelled_via'] ?? null,
        );
        $this->assertSame(AuditEmailHasher::hash(self::PENDING_EMAIL), $properties['pending_email_hash'] ?? null);
    }

    /** Ein Konto ohne offenen Wechsel bekäme sonst einen Abbruch-Eintrag ohne Adresse. */
    public function testSoftDeleteWithoutAnOpenEmailChangeRecordsNoCancellation(): void
    {
        $user = User::factory()->create();
        Activity::query()->delete();

        app(UserSoftDeleterContract::class)->softDelete($user);

        $this->assertSame(
            0,
            Activity::query()->where('event', ActivityEvent::EMAIL_CHANGE_CANCELLED->value)->count(),
        );
    }

    /**
     * Den Recaller-Cookie prüft der Guard gegen Token und Passwort-Hash, und der
     * Token überdauert den Soft-Delete. Ein vorher ausgestellter Cookie meldete
     * nach einem `restore()` sonst wieder an, ohne Passwort und ohne zweiten
     * Faktor.
     */
    public function testSoftDeleteInvalidatesARememberMeCookieForALaterRestore(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);

        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $recaller = $user->id . '|' . $user->getRememberToken() . '|'
            . $guard->hashPasswordForCookie($user->getAuthPassword());

        $this->assertTrue($this->recallerLogsIn($recaller));

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue($trashed->restore());

        $this->assertFalse($this->recallerLogsIn($recaller));
    }

    /**
     * Außerhalb von `session.driver = database` erreicht der Soft-Delete keine
     * Session, und solange jemand sie aufruft, läuft sie nicht ab. Sie trägt die
     * ID des Kontos und meldete nach einem `restore()` wieder an, ohne Passwort
     * und ohne zweiten Faktor.
     *
     * Vor dem Löschen ruft die Session keine geschützte Seite auf, wie eine, die
     * der Weiterleitung nach dem Login nicht gefolgt ist: In ihr liegt dann nur,
     * was der Login hinterlegt hat.
     */
    public function testSoftDeleteEndsAnOldSessionForALaterRestore(): void
    {
        $user = User::factory()->create();

        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $guard->login($user);

        Auth::forgetGuards();
        $this->assertSame($user->id, Auth::guard('web')->id());

        app(UserSoftDeleterContract::class)->softDelete($user);

        $trashed = User::query()->withTrashed()->whereKey($user->getKey())->firstOrFail();
        $this->assertTrue($trashed->restore());

        // Wie jeder echte Request lädt ein frischer Guard das Konto über die ID
        // aus der Session neu.
        Auth::forgetGuards();

        $this->get('/user/profile')->assertRedirect(route('login', absolute: false));
    }

    /**
     * Ein Request, der sich vor dem Löschen angemeldet hat, schreibt noch danach.
     * Sein Passkey meldete nach dem Restore ohne Passwort an, und er läuft nicht
     * ab.
     */
    public function testRestoreRemovesAPasskeyAStragglerAddedAfterTheSoftDelete(): void
    {
        $user = User::factory()->create();
        $straggler = User::query()->whereKey($user->getKey())->firstOrFail();

        app(UserSoftDeleterContract::class)->softDelete($user);
        PasskeyCredential::factory()->for($straggler)->create();

        $this->restoreViaCommand($user);

        $this->assertSame(0, PasskeyCredential::query()->where('user_id', $user->getKey())->count());
    }

    /** Das Passwort, das ein Nachzügler gesetzt hat, kennt er. */
    public function testRestoreDiscardsAPasswordAStragglerSetAfterTheSoftDelete(): void
    {
        $user = User::factory()->create();
        $straggler = User::query()->whereKey($user->getKey())->firstOrFail();

        app(UserSoftDeleterContract::class)->softDelete($user);
        $straggler->forceFill(['password' => Hash::make('nachzuegler-passwort')])->saveOrFail();

        $this->restoreViaCommand($user);

        $this->assertFalse(Hash::check('nachzuegler-passwort', $user->refresh()->password));
    }

    /** Mit der Adresse bekäme der Nachzügler den Link zum Zurücksetzen und damit das Konto. */
    public function testRestoreCancelsAnEmailChangeAStragglerRequestedAfterTheSoftDelete(): void
    {
        $user = User::factory()->create();
        $email = $user->email;
        $straggler = User::query()->whereKey($user->getKey())->firstOrFail();

        app(UserSoftDeleterContract::class)->softDelete($user);
        $confirmToken = $this->seedPendingChange($straggler, self::PENDING_EMAIL)['confirm'];

        $this->restoreViaCommand($user);

        $this->post(route('email.change.confirm.perform', ['token' => $confirmToken]))
            ->assertViewIs('auth.email-change-invalid');

        $this->assertSame($email, $user->refresh()->email);
    }

    /**
     * Ein Abbruch beim Restore heißt, dass während des Löschens noch jemand im
     * Konto geschrieben hat. Der eigene Grund hält das auseinander.
     */
    public function testRestoreRecordsTheEmailChangeItCancels(): void
    {
        $user = User::factory()->create();
        $straggler = User::query()->whereKey($user->getKey())->firstOrFail();

        app(UserSoftDeleterContract::class)->softDelete($user);
        $this->seedPendingChange($straggler, self::PENDING_EMAIL);
        Activity::query()->delete();

        $this->restoreViaCommand($user);

        $entry = Activity::query()
            ->where('log_name', ActivityChannel::AUTH->value)
            ->where('event', ActivityEvent::EMAIL_CHANGE_CANCELLED->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertNull($entry->causer_id);
        $this->assertSame($user->getKey(), $entry->subject_id);

        $properties = $entry->properties?->toArray() ?? [];
        $this->assertSame(EmailChangeCancellationReason::ACCOUNT_RESTORED->value, $properties['cancelled_via'] ?? null);
    }

    /** Die Abräumung nähme einem aktiven Konto Passwort und Passkeys. */
    public function testRestoreRefusesAnActiveAccount(): void
    {
        $user = User::factory()->create();
        PasskeyCredential::factory()->for($user)->create();

        try {
            app(UserSoftDeleterContract::class)->restore($user);
            $this->fail('Ein aktives Konto darf nicht durch den Restore laufen.');
        } catch (ModelNotFoundException) {
            // Erwartet: Der Restore findet nur gelöschte Konten.
        }

        $this->assertSame(1, PasskeyCredential::query()->where('user_id', $user->getKey())->count());
    }

    public function testRestoreInvalidatesAPasswordResetLinkIssuedAfterTheSoftDelete(): void
    {
        $user = User::factory()->create();
        $straggler = User::query()->whereKey($user->getKey())->firstOrFail();

        app(UserSoftDeleterContract::class)->softDelete($user);
        $token = Password::broker()->createToken($straggler);

        $this->restoreViaCommand($user);

        $this->assertFalse(Password::broker()->tokenExists($user->refresh(), $token));
    }

    /**
     * Die Token-Zeile trägt die Adresse im Klartext und überdauert das Konto, das
     * dieser Pfad gerade restlos entfernt hat (DSGVO Art. 17). Wer sich mit
     * derselben Adresse neu registriert, erbt zudem den offenen Link.
     */
    public function testHardDeleteLeavesNoPasswordResetTokenBehind(): void
    {
        $user = User::factory()->create();

        $token = Password::broker()->createToken($user);

        app(UserHardDeleterContract::class)->forceDelete($user, ActivityEvent::ACCOUNT_SELF_DELETED);

        $this->assertFalse(Password::broker()->tokenExists($user, $token));
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
     * Frischer Guard ohne Session, nur mit dem Cookie: die Lage dessen, dessen
     * Session inzwischen abgelaufen ist.
     */
    private function recallerLogsIn(string $recaller): bool
    {
        Auth::forgetGuards();
        Session::flush();

        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $request = Request::create('/');
        $request->cookies->set($guard->getRecallerName(), $recaller);
        $guard->setRequest($request);

        return $guard->check();
    }

    private function restoreViaCommand(User $user): void
    {
        $command = $this->artisan('user:restore');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), $user->email)
            ->expectsConfirmation(__('commands.restore_user.confirm_restore'), 'yes')
            ->assertSuccessful()
            ->run();
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
