<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ServicesDependOnlyOnModelsRepositoryContractsAndServicesTest
{
    public function testServicesDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Services'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Config'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('App\\Notifications'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('App\\Services'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Webauthn'),
                Selector::inNamespace('Cose'),
                Selector::inNamespace('ParagonIE'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Spatie\\Activitylog'),
                Selector::inNamespace('Laravel\\Sanctum'),
                Selector::classname(\Throwable::class),
                Selector::isThrowable(),
                Selector::classname(\GdImage::class),
                Selector::classname(\BackedEnum::class),
                Selector::classname(\UnitEnum::class),
            )
            ->because(
                'Services dürfen nur von Config, DTOs, Models, Notifications, '
                . 'Repository-Contracts, anderen Services und den freigegebenen '
                . 'Vendor-Paketen abhängen.',
                'Abhängigkeiten zur Präsentationsschicht würden die Service-Schicht an '
                . 'die Infrastruktur koppeln. Spatie\\Activitylog ist als Cross-Cutting-'
                . 'Concern (Audit-Logging) freigegeben — symmetrisch zu Listenern, die '
                . 'das Paket bereits nutzen. App\\Notifications ist aus demselben Grund '
                . 'freigegeben: Notifications sind ausgehende Infrastruktur-Adapter '
                . '(Mail-Versand) und keine Präsentationsschicht im engeren Sinn — '
                . 'symmetrisch zur Activity-Log-Freigabe. Laravel\\Sanctum ist '
                . 'freigegeben, weil das `PersonalAccessToken`-Model in DSGVO-Hard-'
                . 'Delete-Pfaden direkt angesprochen werden muss (kein Repository-'
                . 'Wrapper sinnvoll, da Sanctum sein Model selbst exponiert). Die '
                . 'gesamte `\\Throwable`-Hierarchie ist freigegeben, damit Services '
                . 'eigene Exception-Typen definieren können (z. B. fachliche Service-'
                . 'Fehler unter `App\\Services\\*\\Exceptions\\`), ohne dass jede '
                . 'Basisklasse separat aufgelistet werden muss. `\\GdImage` ist als '
                . 'PHP-Core-Wertobjekt der GD-Extension freigegeben — der natürliche '
                . 'Datentyp für Bildverarbeitung (`ProfilePhotoOptimizer`), keine '
                . 'Infrastruktur-Kopplung. `\\BackedEnum` und `\\UnitEnum` sind '
                . 'als PHP-Core-Interfaces freigegeben, damit Services interne '
                . 'Backed-/Unit-Enums definieren können (CLAUDE.md fordert Enums '
                . 'statt Magic-Strings) — analog zur `\\Throwable`-Freigabe. Neue '
                . 'Vendor-Pakete müssen bewusst in die Allowlist eingetragen werden.',
            );
    }

    public function testServiceContractsMustBeInterfaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true))
            ->should()
            ->beInterface()
            ->because(
                'Contracts-Namespaces unterhalb von App\\Services sind ausschließlich für Interfaces vorgesehen.',
                'Konkrete Implementierungen gehören in den übergeordneten Service-Namespace.',
            );
    }
}
