<?php

declare(strict_types=1);

namespace Tests\Feature\Schedule;

use App\Services\Schedule\HealthcheckPingPhase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sichert das Glue zwischen Laravel-Scheduler und `ScheduleHealthcheckPinger`:
 * der Macro registriert je einen before-/success-/failure-Hook und gibt das
 * Event zur weiteren Verkettung zurück. Die eigentliche Ping-Mechanik (URL,
 * Phasen-Suffix, Skip ohne Ping-Key) wird in `ScheduleHealthcheckPingerTest`
 * isoliert geprüft — hier nur Integration über die Scheduler-Hooks.
 */
final class WithHealthcheckMacroTest extends TestCase
{
    public function testMacroIsRegisteredOnScheduledEvent(): void
    {
        $this->assertTrue(Event::hasMacro('withHealthcheck'));
    }

    public function testMacroReturnsEventForChaining(): void
    {
        $event = Schedule::command('inspire')->withHealthcheck('any_slug');

        $this->assertInstanceOf(Event::class, $event);
    }

    public function testBeforeHookPingsStartPhase(): void
    {
        Http::fake();
        Config::set('healthchecks.base_url', 'https://hc-ping.example');
        Config::set('healthchecks.ping_key', 'pk');

        $event = Schedule::command('inspire')->withHealthcheck('activitylog_clean');

        $this->invokeBeforeCallbacks($event);

        $expectedUrl = 'https://hc-ping.example/pk/activitylog_clean'
            . HealthcheckPingPhase::Start->urlSuffix() . '?create=1';
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === $expectedUrl);
    }

    /**
     * Spult die Before-Callbacks des Events ab. `before()`-Hooks landen
     * in einer protected Property; öffentlicher Trigger existiert in
     * Laravel-Schedulern nicht ohne echten Job-Lauf. Reflection statt
     * Job-Simulation, weil wir hier nur den Wiring-Pfad prüfen wollen.
     */
    private function invokeBeforeCallbacks(Event $event): void
    {
        $reflection = new ReflectionClass($event);
        $property = $reflection->getProperty('beforeCallbacks');
        /** @var array<int, \Closure> $callbacks */
        $callbacks = $property->getValue($event);

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
