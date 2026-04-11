<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ViewComponentsAreIndependentTest
{
    public function testViewComponentsDependOnlyOnIlluminate(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\View'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\View'),
                Selector::inNamespace('Illuminate'),
            )
            ->because(
                'View Components dürfen nur von sich selbst und '
                . 'Illuminate abhängen.',
                'Sie sind reine Präsentations-Wrapper und dürfen keine '
                . 'Abhängigkeiten zu Geschäftslogik, Models oder anderen '
                . 'App-Schichten aufbauen.',
            );
    }
}
