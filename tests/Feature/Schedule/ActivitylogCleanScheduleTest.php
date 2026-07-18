<?php

declare(strict_types=1);

namespace Tests\Feature\Schedule;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sichert, dass die DSGVO-Retention-Schedules (`activitylog:clean`, global und
 * forensic) in production tatsächlich durchlaufen. Spaties Command nutzt das
 * ConfirmableTrait: ohne `--force` bricht `schedule:run` in production
 * non-interaktiv mit „Command cancelled." (Exit 1) ab — die Retention liefe nie
 * und die `activity_log`-Tabelle wüchse unbegrenzt (Art. 5(1)(e) DSGVO).
 */
final class ActivitylogCleanScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function testScheduledCleanCommandsRunUnconfirmedInProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $commands = $this->scheduledCleanCommands();
        $this->assertNotEmpty($commands, 'Kein activitylog:clean-Schedule registriert.');

        foreach ($commands as $command) {
            $this->assertSame(
                Command::SUCCESS,
                Artisan::call($command),
                "Retention-Command bricht in production ab (fehlendes --force): {$command}",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function scheduledCleanCommands(): array
    {
        $commands = [];

        foreach (app(Schedule::class)->events() as $event) {
            $command = $event->command;

            if ($command !== null && str_contains($command, 'activitylog:clean')) {
                $commands[] = 'activitylog:clean' . Str::after($command, 'activitylog:clean');
            }
        }

        return $commands;
    }
}
