<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ActionsAreIndependentTest
{
    public function testActionsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Actions'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Actions'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Models'),
                // App\Enums: zentrale Activity-Event-/Channel-Codes (Magic-String-Ersatz).
                Selector::inNamespace('App\\Enums'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                // Throwables: Eine Action darf jede Exception fangen/werfen und
                // in eine ValidationException übersetzen. Exception-Typen sind
                // Cross-Layer-Signale, kein Vorbei-Greifen an der Persistenz —
                // konkrete Services sind keine Throwables und bleiben blockiert.
                Selector::classname(\Throwable::class),
                Selector::isThrowable(),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                // Activity-Logging direkt aus Actions für DSGVO-relevante
                // Lösch-Pfade (z. B. `DeleteUser` schreibt einen anonymisierten
                // Audit-Eintrag, der den Purge-Block überlebt). Kein generelles
                // Logging-Pattern — eng begrenzt auf die Spatie-Facade.
                Selector::inNamespace('Spatie\\Activitylog\\Facades'),
            )
            ->because(
                'Actions dürfen nur von Models, DTOs, Service-Contracts, '
                . 'der `\\Throwable`-Hierarchie, anderen Actions, Illuminate, '
                . 'Laravel und der Activity-Facade abhängen.',
                'Sie sind von Fortify/Jetstream vorgegebene Einstiegspunkte '
                . 'für Benutzeraktionen. Model-Abhängigkeiten sind erlaubt, '
                . 'weil Fortify/Jetstream Models als Eingabeparameter '
                . 'durchreicht. Jede darüber hinausgehende Geschäftslogik '
                . 'gehört in die Service-Schicht und wird ausschließlich '
                . 'über Service-Contracts eingebunden — konkrete Services, '
                . 'Repository-Contracts oder konkrete Repositories sind '
                . 'nicht erlaubt, damit Actions nicht an der Service-Schicht '
                . 'vorbei direkt auf die Persistenz zugreifen.',
            );
    }
}
