<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class NoCrossLayerDependenciesTest
{
    private const string NS_APP_CONSOLE_COMMANDS = 'App\\Console\\Commands';
    private const string NS_APP_HTTP = 'App\\Http';
    private const string NS_APP_LIVEWIRE = 'App\\Livewire';

    public function testHttpDoesNotDependOnLivewireOrConsoleCommands(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_HTTP))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_LIVEWIRE),
                Selector::inNamespace(self::NS_APP_CONSOLE_COMMANDS),
            )
            ->because(
                'App\\Http darf nicht von App\\Livewire oder App\\Console\\Commands abhängen.',
                'Diese Namespaces sind gleichrangige Präsentationsschichten, die unabhängig voneinander '
                . 'arbeiten müssen. Gemeinsame Logik gehört in Services oder Actions.',
            );
    }

    public function testLivewireDoesNotDependOnHttpOrConsoleCommands(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_LIVEWIRE))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_HTTP),
                Selector::inNamespace(self::NS_APP_CONSOLE_COMMANDS),
            )
            ->because(
                'App\\Livewire darf nicht von App\\Http oder App\\Console\\Commands abhängen.',
                'Livewire-Komponenten sind eine eigenständige Präsentationsschicht und dürfen keine '
                . 'Abhängigkeiten zu Controllern, Requests oder Console-Commands haben.',
            );
    }

    public function testConsoleCommandsDoNotDependOnHttpOrLivewire(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_CONSOLE_COMMANDS))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_HTTP),
                Selector::inNamespace(self::NS_APP_LIVEWIRE),
            )
            ->because(
                'App\\Console\\Commands darf nicht von App\\Http oder App\\Livewire abhängen.',
                'Console-Commands sind eine eigenständige Präsentationsschicht und dürfen keine '
                . 'Abhängigkeiten zu Controllern, Requests oder Livewire-Komponenten haben.',
            );
    }
}
