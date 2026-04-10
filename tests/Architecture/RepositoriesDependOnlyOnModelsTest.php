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
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
            )
            ->because(
                'Repositories bilden die Persistenz-Schicht direkt über den Models und dürfen nur von '
                . 'App\\DataTransferObjects, App\\Models und ihren eigenen Contracts '
                . '(App\\Repositories\\Contracts) abhängen.',
                'Konkrete Repositories dürfen keine Abhängigkeiten untereinander haben — wenn mehrere '
                . 'Repositories orchestriert werden müssen, gehört das in einen Service.',
                'Abhängigkeiten zu Http, Livewire, Console, Services oder anderen höheren Schichten '
                . 'würden die Schichtentrennung verletzen — Repositories sollen ihre Aufrufer nicht kennen.',
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
