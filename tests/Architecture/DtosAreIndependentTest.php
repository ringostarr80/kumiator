<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class DtosAreIndependentTest
{
    public function testDtosDependOnlyOnOtherDtos(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\DataTransferObjects'))
            ->canOnly()
            ->dependOn()
            ->classes(Selector::inNamespace('App\\DataTransferObjects'))
            ->because(
                'DTOs sind reine Datenträger und dürfen ausschließlich von '
                . 'anderen DTOs abhängen.',
                'Weder Framework- noch Vendor-Klassen sind erlaubt — DTOs '
                . 'arbeiten nur mit primitiven Typen und anderen DTOs.',
            );
    }
}
