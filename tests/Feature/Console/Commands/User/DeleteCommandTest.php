<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class DeleteCommandTest extends TestCase
{
    use RefreshDatabase;
    // Symfony-Console-Events werden im Test-Modus standardmäßig nicht auf
    // `Illuminate\Console\Events\CommandStarting`/`CommandFinished` gemappt.
    // Ohne diesen Trait würde der `CaptureConsoleActorListener` für
    // `$this->artisan(...)` nicht feuern und die `cli_actor`-Assertion
    // stillschweigend fehlschlagen.
    use WithConsoleEvents;

    private const string TEST_EMAIL = 'john@example.com';

    public function testUserCanBeDeleted(): void
    {
        User::factory()->create(['name' => 'John Doe', 'email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsOutputToContain('John Doe')
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertNull(User::where('email', self::TEST_EMAIL)->first());
    }

    public function testUserDeletionCanBeCancelled(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'no')
            ->expectsOutputToContain(__('commands.delete_user.aborted'))
            ->assertSuccessful()
            ->run();

        $this->assertNotNull(User::where('email', self::TEST_EMAIL)->first());
    }

    public function testDeleteUserFailsForNonExistentUser(): void
    {
        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), 'unknown@example.com')
            ->expectsOutputToContain(__('commands.common.not_found', ['email' => 'unknown@example.com']))
            ->assertFailed()
            ->run();
    }

    /**
     * Symmetrie zum UI-Pfad (`ApiTokenManager::deleteApiToken()`): jeder beim
     * Admin-Soft-Delete entfernte Sanctum-Token bekommt einen anonymen
     * `api_token_revoked`-Eintrag im `auth`-Log mit Token-Snapshot. Sonst
     * verschwänden die Tokens stumm, weil Sanctums `PersonalAccessToken`
     * kein `LogsActivity`-Trait nutzt.
     */
    public function testApiTokenRevocationsAreLoggedDuringSoftDelete(): void
    {
        $user = User::factory()->create(['email' => self::TEST_EMAIL]);

        $firstToken = $user->tokens()->create([
            'name' => 'CI-Token',
            'token' => Str::random(40),
            'abilities' => ['read'],
        ]);
        $secondToken = $user->tokens()->create([
            'name' => 'Mobile-Token',
            'token' => Str::random(40),
            'abilities' => ['read', 'write'],
        ]);

        Activity::query()->delete();

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        /** @var \Illuminate\Database\Eloquent\Collection<int, Activity> $entries */
        $entries = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'api_token_revoked')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);

        $byTokenName = [];

        foreach ($entries as $entry) {
            $tokenName = $entry->properties?->get('token_name');
            $this->assertIsString($tokenName);
            $byTokenName[$tokenName] = $entry;
        }

        $first = $byTokenName['CI-Token'] ?? null;
        $this->assertNotNull($first);
        $this->assertSame(__('app.activity_api_token_revoked'), $first->description);
        $this->assertNull($first->causer_id);
        $this->assertSame($user->getKey(), $first->subject_id);
        $this->assertSame('user', $first->subject_type);
        $properties = $first->properties?->toArray() ?? [];
        $this->assertSame($firstToken->getKey(), $properties['token_id'] ?? null);
        $this->assertSame(['read'], $properties['abilities'] ?? null);
        // Console-Listener hängt den CLI-Actor an — bestätigt, dass der
        // Eintrag nicht vom UI-Pfad stammt.
        $cliActor = $properties['cli_actor'] ?? null;
        $this->assertIsArray($cliActor);
        $this->assertSame('user:delete', $cliActor['command'] ?? null);

        $second = $byTokenName['Mobile-Token'] ?? null;
        $this->assertNotNull($second);
        $secondProperties = $second->properties?->toArray() ?? [];
        $this->assertSame($secondToken->getKey(), $secondProperties['token_id'] ?? null);
        $this->assertSame(['read', 'write'], $secondProperties['abilities'] ?? null);
    }

    public function testNoApiTokenRevocationEntryWhenUserHasNoTokens(): void
    {
        User::factory()->create(['email' => self::TEST_EMAIL]);

        Activity::query()->delete();

        $command = $this->artisan('user:delete');
        assert($command instanceof PendingCommand);

        $command
            ->expectsQuestion(__('commands.common.ask_email'), self::TEST_EMAIL)
            ->expectsConfirmation(__('commands.delete_user.confirm_delete'), 'yes')
            ->assertSuccessful()
            ->run();

        $this->assertSame(
            0,
            Activity::query()
                ->where('log_name', 'auth')
                ->where('event', 'api_token_revoked')
                ->count(),
        );
    }
}
