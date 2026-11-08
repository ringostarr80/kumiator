<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\UnicodeSqliteConnector;
use App\Enums\SqliteFunction;
use Tests\TestCase;

final class UnicodeSqliteConnectorTest extends TestCase
{
    /**
     * Die Suchtests prüfen das nur mittelbar: Die Verbindung entsteht einmal je
     * Prozess, meist in einem anderen Test, und nur dem ordnet die Coverage den
     * Connector zu. Infection ließe die Suchtests für dessen Mutanten deshalb aus.
     */
    public function testTheConnectionLowersUmlauts(): void
    {
        $pdo = (new UnicodeSqliteConnector())->connect(['database' => ':memory:']);

        $statement = $pdo->query(sprintf("select %s('JÜRGEN ÖZDEMIR')", SqliteFunction::UNICODE_LOWER->value));

        $this->assertNotFalse($statement);
        $this->assertSame('jürgen özdemir', $statement->fetchColumn());
    }
}
