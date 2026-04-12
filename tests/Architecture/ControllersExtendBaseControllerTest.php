<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ControllersExtendBaseControllerTest
{
    public function testControllersMustExtendBaseController(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Controllers'))
            ->excluding(Selector::classname('App\\Http\\Controllers\\Controller'))
            ->should()
            ->extend()
            ->classes(Selector::classname('App\\Http\\Controllers\\Controller'))
            ->because(
                'Alle Klassen in App\\Http\\Controllers müssen von '
                . 'App\\Http\\Controllers\\Controller erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Controller dürfen hier liegen.',
            );
    }
}
