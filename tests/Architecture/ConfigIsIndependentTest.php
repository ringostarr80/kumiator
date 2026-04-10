<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConfigIsIndependentTest
{
    public function testConfigDoesNotDependOnServicesRepositoriesOrHttpLayer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\\Config'),
            )
            ->because(
                'Config-Klassen in App\\Config sind eigenständige, typisierte Wrapper um config()-Werte '
                . 'und dürfen von keiner anderen App-Schicht abhängen.',
                'Abhängigkeiten zu Services, Repositories, Models oder Http würden zirkuläre Kopplungen '
                . 'erzeugen, da diese Schichten ihrerseits von App\\Config abhängen dürfen.',
                'Erlaubt sind ausschließlich Framework-Hilfsfunktionen (config(), env()) und '
                . 'Vendor-Klassen für Typkonstanten.',
            );
    }
}
