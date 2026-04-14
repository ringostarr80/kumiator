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
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Symfony'),
                Selector::classname(\Closure::class),
            )
            ->because(
                'Middleware darf nur von Illuminate, Symfony und Closure '
                . 'abhängen.',
                'Middleware ist reine Infrastruktur für Request/Response-Verarbeitung '
                . 'und bildet eigenständige, voneinander unabhängige Bausteine. '
                . 'Abhängigkeiten zu anderen Middleware-Klassen, Models, Services '
                . 'oder Config deuten auf fehlplatzierte Geschäftslogik hin — '
                . 'gemeinsame Logik gehört in einen Service, ein Trait oder eine '
                . 'Helper-Klasse, nicht in eine weitere Middleware.',
            );
    }
}
