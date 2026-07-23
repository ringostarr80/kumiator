<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Console\ConsoleActorContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Zwei über die Test-Grenze hinweg lebende globale Zustände zentral räumen,
     * die sonst zwischen Tests lecken:
     *
     * - Spatie\Permission cached Roles & Permissions im Application-Container
     *   (`PermissionRegistrar::$permissions`); `RefreshDatabase` leert die DB,
     *   nicht aber diesen Cache — stale Treffer bei Permission-Manipulation
     *   innerhalb einer Methode.
     * - `ConsoleActorContext` hält seinen CLI-Marker in einem statischen Feld,
     *   das ein Console-Test (via `WithConsoleEvents` oder manuelles
     *   `activate()`) setzt; überlebt es die Methode, erbte ein Folge-Test
     *   `cli_actor` samt genulltem Causer.
     *
     * Der zentrale Reset macht das Verhalten deterministisch und erspart
     * einzelnen Test-Klassen den manuellen Aufruf.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        ConsoleActorContext::clearStatically();
    }
}
