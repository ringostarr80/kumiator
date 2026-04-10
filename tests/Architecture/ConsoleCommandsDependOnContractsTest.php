<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConsoleCommandsDependOnContractsTest
{
    public function testConsoleCommandsDoNotDependOnConcreteServicesOrRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Console'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Services'),
                Selector::inNamespace('App\\Repositories'),
            )
            ->excluding(
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('App\\Repositories\\Contracts'),
            )
            ->because(
                'Console-Commands dürfen nur gegen Contracts (Interfaces) aus '
                . 'App\\Services\\*\\Contracts und App\\Repositories\\Contracts programmiert werden.',
                'Konkrete Implementierungen werden über den ServiceProvider per DI verdrahtet — die '
                . 'Console-Schicht kennt nur die Abstraktion, nicht die Implementierung.',
                'Das ermöglicht es, Implementierungen auszutauschen, ohne Commands ändern zu müssen '
                . '(Dependency Inversion Principle).',
            );
    }
}
