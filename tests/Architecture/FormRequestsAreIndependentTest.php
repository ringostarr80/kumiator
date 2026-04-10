<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class FormRequestsAreIndependentTest
{
    public function testFormRequestsMustExtendBaseFormRequest(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Requests'))
            ->should()
            ->extend()
            ->classes(Selector::classname('Illuminate\\Foundation\\Http\\FormRequest'))
            ->because(
                'Alle Klassen in App\\Http\\Requests müssen von Illuminate\\Foundation\\Http\\FormRequest erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte FormRequests dürfen hier liegen.',
            );
    }

    public function testFormRequestsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Http\\Requests'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->excluding(
                Selector::inNamespace('App\\Http\\Requests'),
            )
            ->because(
                'FormRequests dürfen innerhalb von App\\ nur von sich selbst abhängen.',
                'Sie sind ausschließlich für Validierung und Autorisierung zuständig. '
                . 'Geschäftslogik gehört in die Service-Schicht — der Controller übergibt '
                . 'die validierten Daten an den Service.',
            );
    }
}
