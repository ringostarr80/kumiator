<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConsoleCommandsAreIndependentTest
{
    private const string BASE_COMMAND = 'App\\Console\\Commands\\Command';
    private const string COMMANDS_NAMESPACE = 'App\\Console\\Commands';
    private const string OUTPUT_FORMATTER = 'Symfony\\Component\\Console\\Formatter\\OutputFormatter';

    public function testCommandsMustExtendTheBaseCommand(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::COMMANDS_NAMESPACE))
            ->excluding(Selector::classname(self::BASE_COMMAND))
            ->should()
            ->extend()
            ->classes(Selector::classname(self::BASE_COMMAND))
            ->because(
                'Alle Klassen in App\\Console\\Commands müssen von '
                . 'App\\Console\\Commands\\Command erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Artisan-Commands dürfen hier liegen. Die Basisklasse hält '
                . 'Steuerzeichen und Formatter-Tags aus gespeicherten Namen und '
                . 'Adressen vom Terminal des Admins fern.',
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
                // Nur die Basisklasse maskiert damit Ausgaben; Laravel hat dafür keinen eigenen Weg.
                Selector::classname(self::OUTPUT_FORMATTER),
                Selector::classname(\Override::class),
                // Zum Maskieren von Tabellenzellen, die Symfony als Text ausgibt.
                Selector::classname(\Stringable::class),
                Selector::classname(\Throwable::class),
                Selector::isThrowable(),
            )
            ->because(
                'Console-Commands dürfen nur von Models, DTOs, Repository- und '
                . 'Service-Contracts, Illuminate, Laravel, Spatie\\Permission, '
                . 'BaconQrCode, Symfonys OutputFormatter (nur die Basisklasse), '
                . 'dem Attribut \\Override, dem Interface \\Stringable und der '
                . '\\Throwable-Hierarchie abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht. Die gesamte '
                . '\\Throwable-Hierarchie ist freigegeben, damit Commands gebrochene '
                . 'Invarianten laut abbrechen (z. B. `?? throw`) und fachliche oder '
                . 'Vendor-Exceptions fangen können — analog zur Services-Regel und '
                . 'als Ersatz für das projektweit verbotene assert().',
            );
    }

    public function testOnlyTheBaseCommandUsesTheOutputFormatter(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::COMMANDS_NAMESPACE))
            ->excluding(Selector::classname(self::BASE_COMMAND))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::classname(self::OUTPUT_FORMATTER))
            ->because(
                'Nur App\\Console\\Commands\\Command darf Symfonys OutputFormatter nutzen.',
                'Escapen allein lässt Steuer- und Bidi-Zeichen durch. Ausgabe '
                . 'läuft über line(), table() und die Prompts der Basisklasse.',
            );
    }
}
