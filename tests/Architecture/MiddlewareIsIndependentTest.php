<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class MiddlewareIsIndependentTest
{
    public function testMiddlewareDependsOnlyOnInfrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Middleware'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Models\\Contracts'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Symfony'),
                Selector::classname(\Closure::class),
            )
            ->because(
                'Middleware darf nur von Illuminate, Symfony, Closure und '
                . 'App\\Models\\Contracts abhängen.',
                'Middleware ist reine Infrastruktur für Request/Response-Verarbeitung '
                . 'und bildet eigenständige, voneinander unabhängige Bausteine. '
                . 'Abhängigkeiten zu anderen Middleware-Klassen, konkreten Models, '
                . 'Services oder Config deuten auf fehlplatzierte Geschäftslogik hin — '
                . 'gemeinsame Logik gehört in einen Service, ein Trait oder eine '
                . 'Helper-Klasse, nicht in eine weitere Middleware. Model-Contracts '
                . '(z.B. MustBeApproved) sind erlaubt, weil sie das Pendant zu '
                . 'Illuminates eigenen Auth-Contracts (MustVerifyEmail) sind und '
                . 'Middleware ohne sie keinen Zugriff auf den Auth-Status hätte.',
            );
    }
}
