<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class PoliciesDependOnlyOnModelsTest
{
    public function testPoliciesDependOnlyOnModelsAndPolicies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Policies'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Policies'),
            )
            ->because(
                'Policies dürfen innerhalb von App\\ nur von Models und '
                . 'anderen Policies abhängen.',
                'Sie sind ausschließlich für Autorisierungslogik zuständig. '
                . 'Geschäftslogik gehört in die Service-Schicht.',
            );
    }
}
