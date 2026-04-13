<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LivewireComponentsAreIndependentTest
{
    public function testLivewireComponentsMustExtendBaseComponent(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Livewire'))
            ->should()
            ->extend()
            ->classes(Selector::classname('Livewire\\Component'))
            ->because(
                'Alle Klassen in App\\Livewire müssen von '
                . 'Livewire\\Component erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Livewire-Komponenten dürfen hier liegen.',
            );
    }

    public function testLivewireComponentsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Livewire'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Livewire'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Livewire'),
            )
            ->because(
                'Livewire-Komponenten dürfen nur von Models, DTOs, Repository- '
                . 'und Service-Contracts, Illuminate und Livewire abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht.',
            );
    }
}
