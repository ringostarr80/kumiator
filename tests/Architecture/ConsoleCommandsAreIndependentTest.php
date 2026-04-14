<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConsoleCommandsAreIndependentTest
{
    private const string COMMANDS_NAMESPACE = 'App\\Console\\Commands';

    public function testCommandsMustExtendIlluminateCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::COMMANDS_NAMESPACE))
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

    public function testCommandsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::COMMANDS_NAMESPACE))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::COMMANDS_NAMESPACE),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('BaconQrCode'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Spatie\\Permission'),
            )
            ->because(
                'Console-Commands dürfen nur von Models, DTOs, Repository- und '
                . 'Service-Contracts, Illuminate, Laravel, Spatie\\Permission '
                . 'und BaconQrCode abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht.',
            );
    }
}
