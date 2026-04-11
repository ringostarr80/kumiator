<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ActionsDependOnlyOnModelsTest
{
    public function testActionsDependOnlyOnModelsAndActions(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Actions'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Actions'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
            )
            ->because(
                'Actions dürfen nur von Models, anderen Actions, '
                . 'Illuminate und Laravel abhängen.',
                'Sie sind von Fortify/Jetstream vorgegebene Einstiegspunkte '
                . 'für Benutzeraktionen. Geschäftslogik, die darüber '
                . 'hinausgeht, gehört in die Service-Schicht.',
            );
    }
}
