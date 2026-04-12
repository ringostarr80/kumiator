<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ProvidersContainNoBusinessLogicTest
{
    public function testProvidersDoNotDependOnRequestScopedOrPresentationClasses(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Providers'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Console\\Commands'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Http\\Controllers'),
                Selector::inNamespace('App\\Http\\Requests'),
            )
            ->because(
                'Providers dürfen nicht von Controllers, FormRequests, '
                . 'Console-Commands oder DTOs abhängen.',
                'Diese Klassen sind request-scoped oder werden vom Framework als '
                . 'Strings aufgelöst und haben im Bootstrapping nichts zu suchen.',
            );
    }
}
