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

        // `?create=1` legt den Check beim ersten Ping automatisch an
        // (Auto-Provisioning). Ohne dieses Flag antwortet Healthchecks.io mit
        // 404, solange kein Check mit dem Slug existiert — der Ping verpufft.
        // Idempotent: sobald der Check existiert, ist das Flag ein No-Op.
        $url = sprintf(
            '%s/%s/%s%s?create=1',
            HealthchecksConfig::baseUrl(),
            $pingKey,
            $slug,
            $phase->urlSuffix(),
        );

        try {
            $response = Http::timeout(HealthchecksConfig::timeoutSeconds())->get($url);

            // Http::get() wirft bei 4xx/5xx nicht. Ein falscher Ping-Key oder
            // ein gelöschter Check verpuffte damit lautlos — genau der blinde
            // Fleck, den das Monitoring vermeiden soll. Sichtbar machen, aber
            // ohne Re-Throw (der Cron darf daran nicht scheitern).
            if ($response->failed()) {
                Log::warning('Healthcheck-Ping mit Fehler-Status beantwortet', [
                    'slug' => $slug,
                    'phase' => $phase->value,
                    'status' => $response->status(),
                ]);
            }
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
