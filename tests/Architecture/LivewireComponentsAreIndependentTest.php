<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LivewireComponentsAreIndependentTest
{
    public function testLivewireComponentsMustExtendBaseComponent(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Livewire'))
            ->should()
            ->extend()
            ->classes(Selector::classname('Livewire\\Component'))
            ->because(
                'Alle Klassen in App\\Livewire müssen von '
                . 'Livewire\\Component erben.',
                'Andere Klassen gehören nicht in diesen Namespace — nur echte '
                . 'Livewire-Komponenten dürfen hier liegen.',
            );
    }

    public function testLivewireComponentsDependOnlyOnAllowedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Livewire'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Livewire'),
                Selector::inNamespace('App\\Models'),
                // App\Enums: zentrale Activity-Event-/Channel-Codes (Magic-String-Ersatz).
                Selector::inNamespace('App\\Enums'),
                Selector::inNamespace('App\\DataTransferObjects'),
                Selector::inNamespace('App\\Repositories\\Contracts'),
                Selector::inNamespace('/^App\\\\Services\\\\.*\\\\Contracts$/', true),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Livewire'),
                // Erweiterung von Jetstream-Profilkomponenten ist erlaubt, damit
                // wir Standard-Forms (z. B. LogoutOtherBrowserSessionsForm) um
                // projekt-spezifisches Verhalten ergänzen können, ohne sie
                // komplett neu zu implementieren.
                Selector::inNamespace('Laravel\\Jetstream\\Http\\Livewire'),
                // Activity-Logging direkt aus Komponenten für Sicherheits-Events
                // ohne Framework-Hook (z. B. Jetstreams Form feuert kein Event).
                Selector::inNamespace('Spatie\\Activitylog\\Facades'),
                // PHP-Sprach-Builtins (z. B. `\Throwable` in defensiven Catch-
                // Klauseln rund um Audit-Log-Aufrufe) sind keine Service-Dependency
                // und stehen daher ausserhalb dieser Whitelist-Logik.
                Selector::classname('Throwable'),
            )
            ->because(
                'Livewire-Komponenten dürfen nur von Models, DTOs, Repository- '
                . 'und Service-Contracts, Illuminate, Livewire, Jetstream-Profil-'
                . 'komponenten (zur Erweiterung), der Activity-Facade und PHP-'
                . 'Sprach-Builtins (\\Throwable) abhängen.',
                'Sie sind eine Präsentationsschicht und dürfen keine konkreten '
                . 'Services oder Repositories kennen — DI erfolgt über Contracts. '
                . 'Geschäftslogik gehört in die Service-Schicht.',
            );
    }
}
