<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class NamingConventionsTest
{
    public function testControllersMustEndWithControllerSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Controllers'))
            ->should()
            ->beNamed('/Controller$/', true)
            ->because(
                'Alle Klassen in App\\Http\\Controllers müssen mit dem '
                . 'Suffix "Controller" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar und verhindert fehlplatzierte '
                . 'Klassen im Namespace.',
            );
    }

    public function testFormRequestsMustEndWithRequestSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Requests'))
            ->should()
            ->beNamed('/Request$/', true)
            ->because(
                'Alle Klassen in App\\Http\\Requests müssen mit dem Suffix '
                . '"Request" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar.',
            );
    }

    public function testConcreteRepositoriesMustEndWithRepositorySuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Repositories'))
            ->excluding(Selector::inNamespace('App\\Repositories\\Contracts'))
            ->should()
            ->beNamed('/Repository$/', true)
            ->because(
                'Konkrete Repository-Klassen in App\\Repositories müssen mit '
                . 'dem Suffix "Repository" enden.',
                'Der Suffix grenzt sie eindeutig von ihren Contracts ab und '
                . 'macht die Rolle der Klasse erkennbar.',
            );
    }

    public function testRepositoryContractsMustEndWithContractSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Repositories\\Contracts'))
            ->should()
            ->beNamed('/Contract$/', true)
            ->because(
                'Alle Interfaces in App\\Repositories\\Contracts müssen mit '
                . 'dem Suffix "Contract" enden.',
                'So ist sofort erkennbar, dass es sich um eine Abstraktion '
                . 'handelt — und der Name kollidiert nicht mit der konkreten '
                . 'Implementierung.',
            );
    }

    public function testServiceContractsMustEndWithContractSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true))
            ->should()
            ->beNamed('/Contract$/', true)
            ->because(
                'Alle Interfaces in App\\Services\\*\\Contracts müssen mit '
                . 'dem Suffix "Contract" enden.',
                'So ist sofort erkennbar, dass es sich um eine Abstraktion '
                . 'handelt — konsistent zu den Repository-Contracts.',
            );
    }

    public function testPoliciesMustEndWithPolicySuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Policies'))
            ->should()
            ->beNamed('/Policy$/', true)
            ->because(
                'Alle Klassen in App\\Policies müssen mit dem Suffix '
                . '"Policy" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar.',
            );
    }

    public function testProvidersMustEndWithProviderSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Providers'))
            ->should()
            ->beNamed('/Provider$/', true)
            ->because(
                'Alle Klassen in App\\Providers müssen mit dem Suffix '
                . '"Provider" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar.',
            );
    }

    public function testConfigClassesMustEndWithConfigSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Config'))
            ->should()
            ->beNamed('/Config$/', true)
            ->because(
                'Alle Klassen in App\\Config müssen mit dem Suffix "Config" '
                . 'enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar.',
            );
    }

    public function testDtosMustEndWithDataSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\DataTransferObjects'))
            ->should()
            ->beNamed('/Data$/', true)
            ->because(
                'Alle Klassen in App\\DataTransferObjects müssen mit dem '
                . 'Suffix "Data" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar und entspricht dem Namespace-Zweck.',
            );
    }

    public function testListenersMustEndWithListenerSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Listeners'))
            ->should()
            ->beNamed('/Listener$/', true)
            ->because(
                'Alle Klassen in App\\Listeners müssen mit dem Suffix '
                . '"Listener" enden.',
                'Ein einheitlicher Suffix macht die Rolle einer Klasse auf '
                . 'einen Blick erkennbar.',
            );
    }
}
