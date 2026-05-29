<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Schedule;

use App\Services\Schedule\HealthcheckPingPhase;
use App\Services\Schedule\ScheduleHealthcheckPinger;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifiziert URL-Aufbau, Phasen-Suffixe und das Skip-Verhalten ohne
 * konfigurierten Ping-Key (relevant für `local`/`testing`, damit Tests
 * niemals echte Pings an Healthchecks.io senden).
 */
final class ScheduleHealthcheckPingerTest extends TestCase
{
    public function testSkipsPingWhenPingKeyIsMissing(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Config::set('healthchecks.ping_key', null);

        app(ScheduleHealthcheckPinger::class)->ping('any_slug', HealthcheckPingPhase::Success);

        Http::assertNothingSent();
    }

    public function testSkipsPingWhenPingKeyIsEmptyString(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Config::set('healthchecks.ping_key', '   ');

        app(ScheduleHealthcheckPinger::class)->ping('any_slug', HealthcheckPingPhase::Success);

        Http::assertNothingSent();
    }

    #[DataProvider('phaseProvider')]
    public function testPingsConfiguredUrlForPhase(HealthcheckPingPhase $phase, string $expectedSuffix): void
    {
        Http::fake();
        Config::set('healthchecks.base_url', 'https://hc-ping.example');
        Config::set('healthchecks.ping_key', 'project-key-123');

        app(ScheduleHealthcheckPinger::class)->ping('activitylog_clean', $phase);

        $expectedUrl = 'https://hc-ping.example/project-key-123/activitylog_clean' . $expectedSuffix;
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === $expectedUrl);
    }

    public function testTrailingSlashOnBaseUrlIsStripped(): void
    {
        Http::fake();
        Config::set('healthchecks.base_url', 'https://hc-ping.example/');
        Config::set('healthchecks.ping_key', 'key');

        app(ScheduleHealthcheckPinger::class)->ping('slug', HealthcheckPingPhase::Success);

        Http::assertSent(
            static fn (HttpRequest $request): bool => $request->url() === 'https://hc-ping.example/key/slug',
        );
    }

    public function testHttpExceptionIsSwallowedSoCronJobNeverFails(): void
    {
        Http::fake(static function (): void {
            throw new \RuntimeException('Healthchecks.io unreachable');
        });
        Config::set('healthchecks.ping_key', 'key');

        // Würde der Pinger die Exception durchreichen, würde dieser Aufruf
        // den ganzen Test killen. Stattdessen still loggen → kein Re-Throw.
        app(ScheduleHealthcheckPinger::class)->ping('slug', HealthcheckPingPhase::Success);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, array{HealthcheckPingPhase, string}>
     */
    public static function phaseProvider(): array
    {
        return [
            'start phase appends /start' => [HealthcheckPingPhase::Start, '/start'],
            'success phase has no suffix' => [HealthcheckPingPhase::Success, ''],
            'failure phase appends /fail' => [HealthcheckPingPhase::Failure, '/fail'],
        ];
    }
}
