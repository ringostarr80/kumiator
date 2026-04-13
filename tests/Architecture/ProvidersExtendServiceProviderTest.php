<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ProvidersExtendServiceProviderTest
{
    public function testProvidersMustExtendIlluminateServiceProvider(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Providers'))
            ->should()
            ->extend()
            ->classes(Selector::classname('Illuminate\\Support\\ServiceProvider'))
            ->because(
                'Alle Klassen in App\\Providers müssen von '
                . 'Illuminate\\Support\\ServiceProvider erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'ServiceProvider dürfen hier liegen.',
            );
    }
}
