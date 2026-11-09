<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\DatabaseDriver;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Die Migrationen unterscheiden nach Treiber. Läuft MariaDB über den Treiber `mysql`
 * (`DB_CONNECTION=mysql` oder `DB_URL=mysql://…`), übergehen sie still ihre Zweige für
 * MariaDB, etwa die binäre Collation der E-Mail-Spalten. Geprüft wird vor jedem Lauf,
 * weil sich die `.env` nach der Installation noch ändern kann.
 */
final class RejectUnsupportedDatabaseDriverListener
{
    public function handle(MigrationsStarted $event): void
    {
        $driver = DB::getDriverName();

        if (DatabaseDriver::tryFrom($driver) !== null) {
            return;
        }

        throw new UnexpectedValueException(sprintf(
            'Der Datenbanktreiber „%s“ wird nicht unterstützt. Für MariaDB DB_CONNECTION=mariadb setzen, '
            . 'bei DB_URL das Schema mariadb://.',
            $driver,
        ));
    }
}
