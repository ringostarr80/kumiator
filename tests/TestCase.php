<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Spatie\Permission cached Roles & Permissions im Application-Container
     * (`PermissionRegistrar::$permissions`). `RefreshDatabase` leert die DB
     * zwischen Tests, der Container-Cache überlebt aber auf Test-Klassen-Ebene
     * — was bei paralleler Test-Ausführung oder Permission-Manipulation
     * innerhalb einer Methode zu stalen Cache-Treffern führen kann.
     *
     * Dieser zentrale Cache-Reset macht das Verhalten deterministisch und
     * erspart einzelnen Test-Klassen den manuellen Aufruf.
     */
    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
