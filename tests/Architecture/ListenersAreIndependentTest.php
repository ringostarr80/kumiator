<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ListenersAreIndependentTest
{
    public function testListenersDependOnlyOnAllowlistedNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\Listeners'))
            ->canOnly()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\\Models'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Spatie\\Activitylog'),
                Selector::inNamespace('Spatie\\Permission'),
            )
            ->because(
                'Listener reagieren auf Events und schreiben in der Regel nur in infrastrukturelle '
                . 'Gegenstellen (Activity-Log, Notifications, Queues). Sie dürfen daher auf Models, '
                . 'Illuminate, Laravel sowie explizit freigegebene Vendor-Pakete '
                . '(Spatie\\Activitylog, Spatie\\Permission) zugreifen.',
                'Abhängigkeiten zu höheren Schichten (Http, Livewire, Console, Services, Repositories, '
                . 'Actions, ...) sind nicht erlaubt — Listener sind schmale Adapter, die Events in '
                . 'Infrastruktur-Aktionen übersetzen. Fachliche Logik gehört in einen Service oder '
                . 'eine Action, die der Listener aufruft (nicht umgekehrt).',
            );
    }
}
