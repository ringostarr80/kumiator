<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class CommandsExtendBaseCommandTest
{
    public function testCommandsMustExtendIlluminateCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Console\\Commands'))
            ->should()
            ->extend()
            ->classes(Selector::classname('Illuminate\\Console\\Command'))
            ->because(
                'Alle Klassen in App\\Console\\Commands müssen von '
                . 'Illuminate\\Console\\Command erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Artisan-Commands dürfen hier liegen.',
            );
    }
}
