<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ModelsDependOnlyOnModelsTest
{
    public function testModelsDependOnlyOnOtherModelsWithinTheAppNamespace(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Models'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(Selector::inNamespace('App\\Models'))
            ->because(
                'Models bilden die unterste Schicht der Anwendung und dürfen nichts aus höheren Schichten '
                . '(Http, Livewire, Console, Services, Repositories, Actions, ...) kennen.',
                'Erlaubt sind ausschließlich Abhängigkeiten zu anderen Klassen in App\\Models — z.B. für '
                . 'Eloquent-Relationen wie BelongsTo/HasMany.',
                'Wenn ein Model fachliche Logik aus einer höheren Schicht braucht, gehört diese Logik in '
                . 'einen Service oder ein Repository, das das Model nutzt — nicht umgekehrt.',
            );
    }
}
