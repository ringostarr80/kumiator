<?php

declare(strict_types=1);

namespace App\Services\Console\Contracts;

/**
 * Contract für den `ConsoleActorContext`-Marker.
 *
 * Hält pro Artisan-Invocation Informationen über den auslösenden CLI-Akteur
 * (OS-User + Hostname + Command-Signatur) bereit, damit der zentrale
 * `Activity::saving`-Hook im `AppServiceProvider` jedem während der
 * Command-Ausführung entstehenden Activity-Log-Eintrag das `cli_actor`-
 * Property anhängen kann.
 *
 * Trennung in ein Interface, weil der CLI-Listener (`CaptureConsoleActorListener`)
 * den Marker via DI aktiviert/cleart und die Architektur-Regel für Listener
 * konkrete Service-Klassen aussperren würde.
 */
interface ConsoleActorContextContract
{
    /**
     * @param array<string, string> $properties Schon normalisierte Actor-Felder
     *                                          (`os_user`, `hostname`, `command`).
     */
    public function activate(array $properties): void;

    public function clear(): void;

    public function isActive(): bool;
}
