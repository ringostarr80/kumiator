<?php

declare(strict_types=1);

namespace App\Database;

use App\Enums\SqliteFunction;
use Illuminate\Database\Connectors\SQLiteConnector;
use Override;
use PDO;

/**
 * SQLite ändert bei `lower()` und `like` nur ASCII. Eine Suche, die die
 * Schreibweise ignoriert, braucht deshalb eine eigene Funktion, und die hängt
 * am PDO-Objekt. Hier entsteht jedes davon, auch beim Reconnect, und erst,
 * wenn die Verbindung wirklich gebraucht wird.
 */
final class UnicodeSqliteConnector extends SQLiteConnector
{
    /**
     * @param array<string, mixed> $config
     */
    #[Override]
    public function connect(array $config): PDO
    {
        /** @var \Pdo\Sqlite $pdo */
        $pdo = parent::connect($config);

        $pdo->createFunction(SqliteFunction::UNICODE_LOWER->value, mb_strtolower(...), 1);

        return $pdo;
    }
}
