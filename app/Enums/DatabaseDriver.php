<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Die Datenbanken, die die App unterstützt und die CI testet, Gründe in
 * `docs/ADR/0006-datenbanken.md`. Die Werte sind Laravels Treibernamen.
 */
enum DatabaseDriver: string
{
    case SQLITE = 'sqlite';
    case MARIADB = 'mariadb';
    case PGSQL = 'pgsql';
}
