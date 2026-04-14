<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ModelsDependOnlyOnModelsTest
{
    public function testModelsDependOnlyOnAllowlistedClasses(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Models'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Spatie\\Permission'),
            )
            ->because(
                'Models bilden die unterste Schicht der Anwendung und dürfen nur von anderen Models, '
                . 'Illuminate, Laravel und explizit freigegebenen Vendor-Paketen (aktuell '
                . 'Spatie\\Permission) abhängen.',
                'Abhängigkeiten zu höheren Schichten (Http, Livewire, Console, Services, Repositories, '
                . 'Actions, ...) sind nicht erlaubt — wenn ein Model fachliche Logik braucht, gehört '
                . 'diese in einen Service oder ein Repository, das das Model nutzt (nicht umgekehrt).',
                'Neue externe Abhängigkeiten in der Domain-Schicht müssen bewusst entschieden und hier '
                . 'in der Allowlist eingetragen werden, um unkontrollierte Kopplung zu vermeiden.',
            );
    }
}
