<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ServicesDependOnlyOnModelsRepositoryContractsAndServicesTest
{
    public function testServicesDependOnlyOnModelsRepositoryContractsAndServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Services'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('App\\Services'),
            )
            ->because(
                'Services dürfen nur von App\\Models, App\\Repositories\\Contracts und anderen Services '
                . '(inkl. eigener Contracts) abhängen — sie sollen framework-unabhängig bleiben.',
                'Abhängigkeiten zu Http (Request/Response), Livewire, Console oder Providers würden die '
                . 'Service-Schicht an die Infrastruktur koppeln und die Wiederverwendbarkeit einschränken.',
                'Wenn ein Service HTTP- oder Session-Zugriff braucht, sollte der Controller die Daten '
                . 'extrahieren und als einfache Parameter an den Service übergeben.',
            );
    }

    public function testServiceContractsMustBeInterfaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true))
            ->should()
            ->beInterface()
            ->because(
                'Contracts-Namespaces unterhalb von App\\Services sind ausschließlich für Interfaces vorgesehen.',
                'Konkrete Implementierungen gehören in den übergeordneten Service-Namespace.',
            );
    }
}
