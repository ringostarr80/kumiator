<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ServicesDependOnlyOnModelsRepositoryContractsAndServicesTest
{
    public function testServicesDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Services'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Config'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('App\\Services'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Webauthn'),
                Selector::inNamespace('Cose'),
                Selector::inNamespace('ParagonIE'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Spatie\\Activitylog'),
                Selector::classname(\Throwable::class),
            )
            ->because(
                'Services dürfen nur von Config, DTOs, Models, Repository-Contracts, '
                . 'anderen Services und den freigegebenen Vendor-Paketen abhängen.',
                'Abhängigkeiten zur Präsentationsschicht würden die Service-Schicht an '
                . 'die Infrastruktur koppeln. Spatie\\Activitylog ist als Cross-Cutting-'
                . 'Concern (Audit-Logging) freigegeben — symmetrisch zu Listenern, die '
                . 'das Paket bereits nutzen. Neue Vendor-Pakete müssen bewusst in die '
                . 'Allowlist eingetragen werden.',
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
