<?php

declare(strict_types=1);

namespace App\Services\Session;

use App\Models\User;
use App\Services\Session\Contracts\UserSessionTerminatorContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Einziger Zugriffspunkt auf die DB-Session-Zeilen eines Users. `DB::table()`
 * statt Eloquent, weil Sessions kein Default-Model haben; Tabellenname und
 * Connection sind konfigurierbar (`session.table`/`session.connection`) und
 * werden beide berücksichtigt.
 */
final class UserSessionTerminator implements UserSessionTerminatorContract
{
    public function deleteForUser(User $user): void
    {
        if (!$this->usesDatabaseDriver()) {
            return;
        }

        $this->sessions()
            ->where('user_id', $user->getKey())
            ->delete();
    }

    public function countOtherSessionsForUser(int|string $userId, string $currentSessionId): int
    {
        if (!$this->usesDatabaseDriver()) {
            return 0;
        }

        return $this->sessions()
            ->where('user_id', $userId)
            ->where('id', '!=', $currentSessionId)
            ->count();
    }

    private function usesDatabaseDriver(): bool
    {
        return Config::string('session.driver') === 'database';
    }

    private function sessions(): Builder
    {
        $connection = Config::get('session.connection');
        $connection = is_string($connection)
            ? $connection
            : null;

        return DB::connection($connection)->table(Config::string('session.table', 'sessions'));
    }
}
