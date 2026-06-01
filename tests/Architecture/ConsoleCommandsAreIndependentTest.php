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
                // App\Enums: zentrale Activity-Event-/Channel-Codes (Magic-String-Ersatz).
                Selector::inNamespace('App\\Enums'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('BaconQrCode'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Spatie\\Permission'),
                Selector::classname(\Throwable::class),
                Selector::isThrowable(),
            )
            ->because(
                'Console-Commands dürfen nur von Models, DTOs, Repository- und '
                . 'Service-Contracts, Illuminate, Laravel, Spatie\\Permission, '
                . 'BaconQrCode und der \\Throwable-Hierarchie abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht. Die gesamte '
                . '\\Throwable-Hierarchie ist freigegeben, damit Commands gebrochene '
                . 'Invarianten laut abbrechen (z. B. `?? throw`) und fachliche oder '
                . 'Vendor-Exceptions fangen können — analog zur Services-Regel und '
                . 'als Ersatz für das projektweit verbotene assert().',
            );
    }
}
