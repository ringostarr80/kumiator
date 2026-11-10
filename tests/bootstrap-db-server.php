<?php

declare(strict_types=1);

// PHPUnit wendet den <php>-Block der phpunit.xml vor diesem Skript an. Es ersetzt deshalb nur die DB-Werte, alles
// andere gilt unverändert. Der Zugang steht fest, weil die Tests per migrate:fresh alle Tabellen löschen: Ein Wert
// aus der .env träfe sonst die Dev-Datenbank. Die Ports gehören zu docker/test-databases/compose.yml.
$ports = ['mariadb' => '33306', 'pgsql' => '55432'];
$connection = (string) getenv('TEST_DB_SERVER');
$port = $ports[$connection] ?? throw new RuntimeException('TEST_DB_SERVER muss mariadb oder pgsql sein.');

$values = [
    'DB_CONNECTION' => $connection,
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => $port,
    'DB_DATABASE' => 'kumiator_test',
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => '',
    // Leer, weil ein Socket Host und Port aussticht
    'DB_SOCKET' => '',
];

foreach ($values as $name => $value) {
    // Alle drei Wege wie <env> und <server> in der phpunit.xml, weil Laravel $_SERVER zuerst liest
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
