<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ConfigIsIndependentTest
{
    /**
     * Baseline: Config-Klassen dürfen standardmäßig nur von Illuminate
     * abhängen. Config-Klassen mit Vendor-Bedarf werden hier excluded
     * und erhalten unten eine eigene canOnly()-Regel.
     *
     * Neue Config-Klassen werden automatisch von dieser Regel erfasst.
     * Braucht eine neue Klasse ein Vendor-Paket, schlägt diese Regel
     * fehl und erzwingt eine explizite Ausnahme + eigene Regel.
     */
    public function testConfigClassesCanOnlyDependOnIlluminate(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config'))
            ->excluding(
                Selector::classname('App\\Config\\WebauthnConfig'),
            )
            ->canOnly()
            ->dependOn()
            ->classes(Selector::inNamespace('Illuminate'))
            ->because(
                'Config-Klassen dürfen standardmäßig nur von Illuminate abhängen.',
                'Braucht eine Config-Klasse ein Vendor-Paket, muss sie hier '
                . 'excluded und mit einer eigenen Regel versehen werden.',
            );
    }

    public function testWebauthnConfigCanOnlyDependOnWebauthn(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('App\\Config\\WebauthnConfig'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Webauthn'),
            )
            ->because(
                'WebauthnConfig darf nur von Illuminate und Webauthn abhängen.',
                'Jede Config-Klasse darf ausschließlich das Vendor-Paket '
                . 'nutzen, das sie konfiguriert.',
            );
    }
}
