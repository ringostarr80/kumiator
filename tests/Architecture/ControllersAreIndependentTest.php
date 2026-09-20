<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ControllersAreIndependentTest
{
    private const string CONTROLLERS_NAMESPACE = 'App\\Http\\Controllers';

    public function testControllersMustExtendBaseController(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTROLLERS_NAMESPACE))
            ->excluding(Selector::classname('App\\Http\\Controllers\\Controller'))
            ->should()
            ->extend()
            ->classes(Selector::classname('App\\Http\\Controllers\\Controller'))
            ->because(
                'Alle Klassen in App\\Http\\Controllers müssen von '
                . 'App\\Http\\Controllers\\Controller erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Controller dürfen hier liegen.',
            );
    }

    public function testControllersDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::CONTROLLERS_NAMESPACE))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::CONTROLLERS_NAMESPACE),
                Selector::inNamespace('App\\Http\\Requests'),
                // App\Enums: zentrale Activity-Event-/Channel-Codes (Magic-String-Ersatz).
                Selector::inNamespace('App\\Enums'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Config'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Exceptions$/', true),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Webauthn'),
                Selector::classname(\Throwable::class),
                Selector::isThrowable(),
            )
            ->because(
                'Controller dürfen nur von FormRequests, Enums, Models, DTOs, '
                . 'Configs, Repository- und Service-Contracts, Service-Exceptions, '
                . 'Illuminate, Webauthn und der `\\Throwable`-Hierarchie abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht. Fachliche Service-'
                . 'Exceptions (`App\\Services\\*\\Exceptions\\`) sind freigegeben, '
                . 'damit Controller Service-Aufrufe mit `catch` differenzieren können — '
                . 'der einzige sinnvolle Weg, semantische Fehler eines Service-Calls '
                . 'in unterschiedliche View-Antworten zu übersetzen. `App\\Enums` ist '
                . 'freigegeben, weil die Audit-Codes, die ein Controller beim Schreiben '
                . 'benennt, sonst als Magic-Strings dastünden — symmetrisch zur Freigabe '
                . 'in Actions, Models und Services.',
            );
    }
}
