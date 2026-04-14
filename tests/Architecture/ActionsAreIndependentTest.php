<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ActionsAreIndependentTest
{
    public function testActionsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Actions'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Actions'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
            )
            ->because(
                'Actions dürfen nur von Models, DTOs, Service-Contracts, '
                . 'anderen Actions, Illuminate und Laravel abhängen.',
                'Sie sind von Fortify/Jetstream vorgegebene Einstiegspunkte '
                . 'für Benutzeraktionen. Model-Abhängigkeiten sind erlaubt, '
                . 'weil Fortify/Jetstream Models als Eingabeparameter '
                . 'durchreicht. Jede darüber hinausgehende Geschäftslogik '
                . 'gehört in die Service-Schicht und wird ausschließlich '
                . 'über Service-Contracts eingebunden — konkrete Services, '
                . 'Repository-Contracts oder konkrete Repositories sind '
                . 'nicht erlaubt, damit Actions nicht an der Service-Schicht '
                . 'vorbei direkt auf die Persistenz zugreifen.',
            );
    }
}
