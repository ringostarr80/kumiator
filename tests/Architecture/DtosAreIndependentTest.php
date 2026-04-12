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

    public function testDtosMustBeReadonly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\DataTransferObjects'))
            ->should()
            ->beReadonly()
            ->because(
                'DTOs müssen readonly sein, um Immutability zu garantieren.',
                'Nachträgliche Mutationen würden die Semantik eines reinen '
                . 'Datenträgers brechen und zu schwer auffindbaren Bugs führen.',
            );
    }

    public function testDtosMustBeFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\DataTransferObjects'))
            ->should()
            ->beFinal()
            ->because(
                'DTOs müssen final sein — Vererbung ist bei reinen Datenträgern '
                . 'kein legitimes Gestaltungsmittel.',
                'Wird eine Variante benötigt, sollte ein eigener DTO erstellt werden.',
            );
    }
}
