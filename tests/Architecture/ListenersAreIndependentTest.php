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
                // App\Enums: zentrale Activity-Event-/Channel-Codes (Magic-String-Ersatz).
                Selector::inNamespace('App\\Enums'),
                // App\Services: Listener orchestrieren fachliche Logik über den Service-Layer
                // (Context-Marker, Audit-Helfer), statt sie selbst zu enthalten.
                Selector::inNamespace('App\\Services'),
                // App\Listeners: geteilte abstrakte Basis innerhalb derselben Schicht.
                Selector::inNamespace('App\\Listeners'),
                Selector::inNamespace('Illuminate'),
                Selector::inNamespace('Laravel'),
                Selector::inNamespace('Spatie\\Activitylog'),
                Selector::inNamespace('Spatie\\Permission'),
            )
            ->because(
                'Listener reagieren auf Events und schreiben in der Regel nur in infrastrukturelle '
                . 'Gegenstellen (Activity-Log, Notifications, Queues). Sie dürfen daher auf Models, '
                . 'Enums, den Service-Layer (den sie orchestrieren), eine geteilte Listener-Basis, '
                . 'Illuminate, Laravel sowie explizit freigegebene Vendor-Pakete '
                . '(Spatie\\Activitylog, Spatie\\Permission) zugreifen.',
                'Abhängigkeiten zu höheren Schichten (Http, Livewire, Console, Controllers, '
                . 'Middleware, Repositories, Actions, ...) sind nicht erlaubt — Listener sind schmale '
                . 'Adapter, die Events in Infrastruktur-Aktionen übersetzen und fachliche Logik über '
                . 'Services orchestrieren, statt sie selbst zu enthalten.',
            );
    }
}
