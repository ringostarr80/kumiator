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
