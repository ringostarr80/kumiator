<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

final class UnsupportedDatabaseDriverTest extends TestCase
{
    /**
     * @param array<string, string> $connection
     */
    #[DataProvider('connectionsWithTheMysqlDriver')]
    public function testMigrationsRefuseTheMysqlDriver(array $connection): void
    {
        Config::set('database.connections.under_test', $connection);
        DB::setDefaultConnection('under_test');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs(
            'Der Datenbanktreiber „mysql“ wird nicht unterstützt. Für MariaDB DB_CONNECTION=mariadb setzen, '
            . 'bei DB_URL das Schema mariadb://.',
        );

        Event::dispatch(new MigrationsStarted('up'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function connectionsWithTheMysqlDriver(): iterable
    {
        yield 'DB_CONNECTION=mysql' => [['driver' => 'mysql', 'database' => 'kumiator']];

        // Das Schema der URL überschreibt den Treiber der Verbindung.
        yield 'DB_URL=mysql://…' => [['driver' => 'mariadb', 'url' => 'mysql://127.0.0.1/kumiator']];
    }
}
