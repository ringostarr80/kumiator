<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Config\HealthchecksConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sendet Heartbeat-Pings an Healthchecks.io (oder einen kompatiblen Endpoint)
 * für Laravel-Scheduler-Jobs. URL-Schema: `<base>/<ping-key>/<slug>[<phase>]`.
 *
 * Auto-Provisioning: Healthchecks.io legt den Check beim ersten Ping mit neuem
 * Slug an. Die erwartete Frequenz wird dort einmalig pro Check im Dashboard
 * eingestellt — der Pinger selbst kennt die Schedule-Intervalle nicht.
 *
 * Failure-Mode: Ein Healthchecks.io-Ausfall darf den eigentlichen Cron-Job
 * NICHT kippen. Exceptions aus dem HTTP-Call werden geschluckt und nur
 * geloggt — der Schedule muss auch ohne erreichbares Monitoring laufen.
 */
final class ScheduleHealthcheckPinger
{
    public function ping(string $slug, HealthcheckPingPhase $phase): void
    {
        $pingKey = HealthchecksConfig::pingKey();

        // Ohne konfigurierten Ping-Key wird stillschweigend übersprungen.
        // Relevant für `local`/`testing` (keine echten Pings im Dev) und
        // als Fail-Safe in Deployments ohne aktiviertes Monitoring.
        if ($pingKey === null) {
            return;
        }

        $url = sprintf(
            '%s/%s/%s%s',
            HealthchecksConfig::baseUrl(),
            $pingKey,
            $slug,
            $phase->urlSuffix(),
        );

        try {
            Http::timeout(HealthchecksConfig::timeoutSeconds())->get($url);
        } catch (Throwable $exception) {
            Log::warning('Healthcheck-Ping fehlgeschlagen', [
                'slug' => $slug,
                'phase' => $phase->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
