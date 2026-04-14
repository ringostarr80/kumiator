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
     * brauchen lediglich eine weitere canOnly()-Regel unten — kein
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

    public function testWebauthnVendorConfigCanOnlyDependOnWebauthn(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config\\Vendor\\Webauthn'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Webauthn'),
            )
            ->because(
                'Config-Klassen unter App\\Config\\Vendor\\Webauthn dürfen nur '
                . 'von Illuminate und Webauthn abhängen.',
                'Jeder Vendor-Sub-Namespace darf ausschließlich das Vendor-Paket '
                . 'nutzen, das er konfiguriert.',
            );
    }
}
