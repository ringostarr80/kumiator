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
                Selector::inNamespace('Spatie\\Activitylog'),
                Selector::inNamespace('Spatie\\Permission'),
                // Sprachprimitive im Root-Namespace sind keine Vendor-Abhängigkeiten,
                // sondern Bausteine von PHP selbst — analog zu den anderen Schicht-
                // Allowlists (Controllers/Services/Actions). Ohne diese Einträge
                // müssten Models defensives `try/catch` oder `Stringable`-Casts an
                // höhere Schichten delegieren, was die Domain unnötig anämisch macht.
                Selector::classname(\Throwable::class),
                Selector::classname(\Stringable::class),
                Selector::classname(\DateTimeInterface::class),
                Selector::classname(\JsonSerializable::class),
            )
            ->because(
                'Models bilden die unterste Schicht der Anwendung und dürfen nur von anderen Models, '
                . 'Illuminate, Laravel, explizit freigegebenen Vendor-Paketen (aktuell '
                . 'Spatie\\Activitylog und Spatie\\Permission) sowie PHP-Sprachprimitiven (Throwable, '
                . 'Stringable, DateTimeInterface, JsonSerializable) abhängen.',
                'Abhängigkeiten zu höheren Schichten (Http, Livewire, Console, Services, Repositories, '
                . 'Actions, ...) sind nicht erlaubt — wenn ein Model fachliche Logik braucht, gehört '
                . 'diese in einen Service oder ein Repository, das das Model nutzt (nicht umgekehrt).',
                'Neue externe Abhängigkeiten in der Domain-Schicht müssen bewusst entschieden und hier '
                . 'in der Allowlist eingetragen werden, um unkontrollierte Kopplung zu vermeiden.',
            );
    }
}
