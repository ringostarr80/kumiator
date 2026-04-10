<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class NoCrossLayerDependenciesTest
{
    private const string NS_APP_CONSOLE = 'App\\Console';
    private const string NS_APP_HTTP = 'App\\Http';
    private const string NS_APP_LIVEWIRE = 'App\\Livewire';

    public function testHttpDoesNotDependOnLivewireOrConsole(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_HTTP))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_LIVEWIRE),
                Selector::inNamespace(self::NS_APP_CONSOLE),
            )
            ->because(
                'App\\Http darf nicht von App\\Livewire oder App\\Console abhängen.',
                'Diese Namespaces sind gleichrangige Präsentationsschichten, die unabhängig voneinander '
                . 'arbeiten müssen. Gemeinsame Logik gehört in Services oder Actions.',
            );
    }

    public function testLivewireDoesNotDependOnHttpOrConsole(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_LIVEWIRE))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_HTTP),
                Selector::inNamespace(self::NS_APP_CONSOLE),
            )
            ->because(
                'App\\Livewire darf nicht von App\\Http oder App\\Console abhängen.',
                'Livewire-Komponenten sind eine eigenständige Präsentationsschicht und dürfen keine '
                . 'Abhängigkeiten zu Controllern, Requests oder Console-Commands haben.',
            );
    }

    public function testConsoleDoesNotDependOnHttpOrLivewire(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS_APP_CONSOLE))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS_APP_HTTP),
                Selector::inNamespace(self::NS_APP_LIVEWIRE),
            )
            ->because(
                'App\\Console darf nicht von App\\Http oder App\\Livewire abhängen.',
                'Console-Commands sind eine eigenständige Präsentationsschicht und dürfen keine '
                . 'Abhängigkeiten zu Controllern, Requests oder Livewire-Komponenten haben.',
            );
    }
}
