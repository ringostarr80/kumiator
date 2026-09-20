<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConfigIsIndependentTest
{
    /**
     * Baseline: Nicht-Vendor-Config-Klassen dürfen nur von Illuminate
     * abhängen. Alles unterhalb von App\Config\Vendor\* ist ausgenommen
     * und erhält eigene Regeln (eine pro Vendor-Sub-Namespace).
     *
     * Neue Nicht-Vendor-Configs werden automatisch erfasst. Neue Vendor-
     * Configs werden unter App\Config\Vendor\{VendorName}\ angelegt und
     * brauchen lediglich eine canOnly()-Regel unten — kein
     * Per-Klasse-Exclude mehr.
     */
    public function testNonVendorConfigClassesCanOnlyDependOnIlluminate(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config'))
            ->excluding(Selector::inNamespace('App\\Config\\Vendor'))
            ->canOnly()
            ->dependOn()
            ->classes(Selector::inNamespace('Illuminate'))
            ->because(
                'Nicht-Vendor-Config-Klassen dürfen nur von Illuminate abhängen.',
                'Braucht eine Config-Klasse ein Vendor-Paket, gehört sie unter '
                . 'App\\Config\\Vendor\\{VendorName}\\ und bekommt dort eine '
                . 'eigene canOnly()-Regel.',
            );
    }
}
