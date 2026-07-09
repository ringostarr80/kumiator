<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Console\Contracts\ConsoleActorContextContract;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/**
 * Hängt sich an den Artisan-Lifecycle, um pro Single-Shot-Command-Invocation
 * den `ConsoleActorContext`-Marker mit OS-User, Hostname und Command-Signatur
 * zu füllen — Quelle für das `cli_actor`-Property im Activity-Log.
 *
 * Registrierung läuft über Laravels Listener-Auto-Discovery (Type-Hints der
 * `handle*`-Methoden), symmetrisch zu `LogRoleChangeListener` und
 * `LogAuthenticationActivityListener`. Keine zusätzliche `Event::listen`-
 * Bindung — würde sonst doppelt feuern.
 *
 * Robustheit gegenüber unbekannten Hosts: `posix_*` ist nicht überall
 * verfügbar (z. B. Windows-Devs, Container ohne POSIX-Erweiterung), daher
 * `function_exists`-Guards mit `get_current_user()` als Fallback. `gethostname()`
 * kann theoretisch `false` liefern; das wird auf den Klartext `'unknown'`
 * abgebildet, damit der Audit-Eintrag immer befüllbar ist.
 */
final class CaptureConsoleActorListener
{
    /**
     * Commands, die fremde Arbeit hosten und selbst kein Admin-Akteur sind:
     * ihr Marker wiese jeden dort geschriebenen Eintrag fälschlich als
     * CLI-Aktion mit genulltem Causer aus. Zwei Sorten fallen darunter —
     * langlebige Worker, die `CommandFinished` erst beim Shutdown feuern und
     * den Marker so über jeden verarbeiteten Job spannen, und `schedule:run`,
     * das zwar kurzlebig ist, aber `Schedule::call`-Closures und sync
     * dispatchte Jobs im eigenen Prozess ausführt. `schedule:work` allein
     * genügt dafür nicht: der Daemon startet `schedule:run` als Subprozess,
     * und dort entstehen die Einträge.
     *
     * Bewusst eine Namens-Denylist und kein generelles Signal: Laravel kennt
     * dafür keine Kennzeichnung (kein gemeinsames Interface, `CommandStarting`
     * trägt nur den Namen). Die Liste deckt die First-Party-Hosts ab; ein
     * eigener Daemon kommt als ein Eintrag dazu.
     */
    private const WORK_HOSTING_COMMANDS = [
        'queue:work',
        'queue:listen',
        'schedule:run',
        'schedule:work',
        'horizon',
        'horizon:work',
        'horizon:supervisor',
        'octane:start',
        'reverb:start',
    ];

    public function __construct(private readonly ConsoleActorContextContract $context)
    {
    }

    public function handleStarting(CommandStarting $event): void
    {
        if (in_array($event->command, self::WORK_HOSTING_COMMANDS, true)) {
            return;
        }

        $this->context->activate([
            'os_user' => self::resolveOsUser(),
            'hostname' => self::resolveHostname(),
            'command' => $event->command ?? '',
        ]);
    }

    public function handleFinished(CommandFinished $event): void
    {
        unset($event);

        $this->context->clear();
    }

    private static function resolveOsUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            // posix_getpwuid liefert in Standard-Container/Linux-Setups
            // immer einen `name`-Eintrag; in exotischen Setups (UID ohne
            // /etc/passwd-Mapping) kann es `false` zurückgeben — dann
            // fällt der Aufruf auf `get_current_user()` durch.
            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        $current = get_current_user();

        return $current === ''
            ? 'unknown'
            : $current;
    }

    private static function resolveHostname(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== ''
            ? $host
            : 'unknown';
    }
}
