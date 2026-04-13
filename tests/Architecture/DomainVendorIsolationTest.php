<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class DomainVendorIsolationTest
{
    public function testModelsDependOnlyOnAllowedVendorPackages(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Models'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::all())
            ->excluding(
                // App-intern (durch ModelsDependOnlyOnModelsTest weiter eingeschränkt auf App\Models)
                Selector::inNamespace('App'),
                // Laravel-Kern
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                // Erlaubte Vendor-Pakete
                Selector::inNamespace('Spatie\\Permission'),
            )
            ->because(
                'Models dürfen nur von explizit freigegebenen Vendor-Paketen abhängen.',
                'Neue externe Abhängigkeiten in der Domain-Schicht müssen bewusst entschieden und hier '
                . 'in der Allowlist eingetragen werden, um unkontrollierte Kopplung zu vermeiden.',
            );
    }
}
