<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class PoliciesDependOnlyOnModelsTest
{
    public function testPoliciesDependOnlyOnModels(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Policies'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Models'),
            )
            ->because(
                'Policies dürfen nur von Models abhängen.',
                'Jede Policy ist eine eigenständige Autorisierungs-Einheit pro Model. '
                . 'Gemeinsame Logik gehört in ein Trait oder einen Service.',
            );
    }
}
