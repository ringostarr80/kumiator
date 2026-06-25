<?php

declare(strict_types=1);

namespace Tests\Feature\Session;

use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserSessionTerminatorTest extends TestCase
{
    use RefreshDatabase;

    public function testDeleteForUserRemovesOnlyThatUsersSessions(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->insertSession('sess-user', $user->id);
        $this->insertSession('sess-other', $other->id);

        app(UserSessionTerminatorContract::class)->deleteForUser($user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function testDeleteForUserRespectsConfiguredSessionConnection(): void
    {
        // Sessions auf eine eigene, physisch getrennte Connection legen (zweites
        // In-Memory-SQLite) und prüfen, dass der Service genau dort löscht — die
        // Default-Connection muss er bei gesetzter `session.connection` unangetastet
        // lassen.
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

        $user = User::factory()->create();

        DB::connection('session_store')->table('sessions')->insert($this->sessionRow('on-store', $user->id));
        DB::table('sessions')->insert($this->sessionRow('on-default', $user->id));

        app(UserSessionTerminatorContract::class)->deleteForUser($user);

        $this->assertSame(
            0,
            DB::connection('session_store')->table('sessions')->where('user_id', $user->id)->count(),
            'Die Session auf der konfigurierten Connection muss gelöscht werden.',
        );
        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'Die Default-Connection darf bei gesetzter session.connection nicht angefasst werden.',
        );
    }

    public function testDeleteForUserIsNoOpWhenDriverIsNotDatabase(): void
    {
        Config::set('session.driver', 'array');

        $user = User::factory()->create();
        // Zeile bleibt liegen (z. B. nach Treiber-Wechsel): Ohne `database`-Treiber
        // ist der Service nicht zuständig und darf nichts anfassen.
        $this->insertSession('sess-user', $user->id);

        app(UserSessionTerminatorContract::class)->deleteForUser($user);

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function testCountOtherSessionsExcludesCurrentSession(): void
    {
        Config::set('session.driver', 'database');

        $user = User::factory()->create();
        $this->insertSession('current', $user->id);
        $this->insertSession('other-1', $user->id);
        $this->insertSession('other-2', $user->id);

        $count = app(UserSessionTerminatorContract::class)
            ->countOtherSessionsForUser($user->id, 'current');

        $this->assertSame(2, $count);
    }

    public function testCountOtherSessionsIsZeroWhenDriverIsNotDatabase(): void
    {
        Config::set('session.driver', 'array');

        $user = User::factory()->create();
        $this->insertSession('current', $user->id);
        $this->insertSession('other', $user->id);

        $count = app(UserSessionTerminatorContract::class)
            ->countOtherSessionsForUser($user->id, 'current');

        $this->assertSame(0, $count);
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert($this->sessionRow($id, $userId));
    }

    /**
     * @return array<string, int|string>
     */
    private function sessionRow(string $id, int $userId): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ];
    }
}
