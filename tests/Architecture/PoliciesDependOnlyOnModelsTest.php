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
                Selector::inNamespace('Illuminate\\Auth\\Access'),
                Selector::inNamespace('Illuminate\\Contracts\\Auth'),
            )
            ->because(
                'Policies dürfen nur von Models und dem Laravel-Policy-Pattern '
                . '(Illuminate\\Auth\\Access, Illuminate\\Contracts\\Auth) abhängen.',
                'Jede Policy ist eine eigenständige Autorisierungs-Einheit pro Model. '
                . 'Gemeinsame Logik gehört in ein Trait oder einen Service. Erlaubt '
                . 'sind nur Bordmittel des Policy-Patterns selbst: Response, '
                . 'HandlesAuthorization und Authenticatable-Contract.',
            );
    }
}
