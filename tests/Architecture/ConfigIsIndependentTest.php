<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConfigIsIndependentTest
{
    /**
     * Baseline: Nicht-Vendor-Config-Klassen dürfen nur von Illuminate und
     * der `\Throwable`-Hierarchie abhängen. Alles unterhalb von
     * App\Config\Vendor\* ist ausgenommen und erhält eigene Regeln (eine pro
     * Vendor-Sub-Namespace).
     *
     * Neue Nicht-Vendor-Configs werden automatisch erfasst. Neue Vendor-
     * Configs werden unter App\Config\Vendor\{VendorName}\ angelegt und
     * brauchen lediglich eine canOnly()-Regel unten — kein
     * Per-Klasse-Exclude mehr.
     */
    public function testNonVendorConfigClassesCanOnlyDependOnIlluminateAndThrowables(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config'))
            ->excluding(Selector::inNamespace('App\\Config\\Vendor'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Illuminate'),
                // Throwables: Einen ungültigen Wert meldet eine Config-Klasse
                // mit einer Exception, statt ihn still umzudeuten.
                Selector::isThrowable(),
            )
            ->because(
                'Nicht-Vendor-Config-Klassen dürfen nur von Illuminate und der '
                . '`\\Throwable`-Hierarchie abhängen.',
                'Braucht eine Config-Klasse ein Vendor-Paket, gehört sie unter '
                . 'App\\Config\\Vendor\\{VendorName}\\ und bekommt dort eine '
                . 'eigene canOnly()-Regel.',
            );
    }
}
