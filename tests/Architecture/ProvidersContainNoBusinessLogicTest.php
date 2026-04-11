<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ProvidersContainNoBusinessLogicTest
{
    public function testProvidersDoNotDependOnHttpOrConsole(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Providers'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Http'),
                Selector::inNamespace('App\\Console'),
            )
            ->because(
                'Providers dürfen nicht von App\\Http oder App\\Console abhängen.',
                'Beide Schichten werden vom Framework automatisch verdrahtet '
                . '— eine direkte Abhängigkeit deutet auf fehlplatzierte Logik hin.',
            );
    }
}
