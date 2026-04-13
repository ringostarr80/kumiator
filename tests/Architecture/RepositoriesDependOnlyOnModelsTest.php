<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class RepositoriesDependOnlyOnModelsTest
{
    public function testRepositoriesDependOnlyOnModelsAndContracts(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Repositories'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('Illuminate'),
            )
            ->because(
                'Repositories bilden die Persistenz-Schicht direkt über den Models und dürfen nur von '
                . 'App\\DataTransferObjects, App\\Models, ihren eigenen Contracts '
                . '(App\\Repositories\\Contracts) und Illuminate abhängen.',
                'Konkrete Repositories dürfen keine Abhängigkeiten untereinander haben — wenn mehrere '
                . 'Repositories orchestriert werden müssen, gehört das in einen Service.',
                'Abhängigkeiten zu Http, Livewire, Console, Services oder anderen höheren Schichten '
                . 'würden die Schichtentrennung verletzen — Repositories sollen ihre Aufrufer nicht kennen.',
                'Externe Vendor-Pakete sind ebenfalls nicht erlaubt: die gesamte Vendor-spezifische '
                . 'Logik (Serialisierung, WebAuthn-Typen) gehört in die Service-Schicht. Repositories '
                . 'arbeiten nur mit Eloquent, DTOs und primitiven Typen.',
            );
    }

    public function testRepositoryContractsMustBeInterfaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Repositories\\Contracts'))
            ->should()
            ->beInterface()
            ->because(
                'Der Namespace App\\Repositories\\Contracts ist ausschließlich für Interfaces vorgesehen.',
                'Konkrete Implementierungen gehören direkt nach App\\Repositories.',
            );
    }
}
