<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Der Fehler entsteht, bevor das Statement die Datenbank erreicht, und lässt die Transaktion des Tests deshalb
 * intakt. Eine gelöschte Tabelle nähme sie mit: MariaDB schließt sie mit dem DDL ab, PostgreSQL lehnt nach dem
 * gescheiterten Statement jede weitere Abfrage darin ab.
 */
trait FailsQueries
{
    protected function failQueriesOn(string $table): void
    {
        DB::beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use ($table): void {
            if (str_contains($sql, $connection->getQueryGrammar()->wrapTable($table))) {
                throw new QueryException(
                    DB::getDefaultConnection(),
                    $sql,
                    $bindings,
                    new PDOException('Simulierter Fehler'),
                );
            }
        });
    }
}
