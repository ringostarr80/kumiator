<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Schedule-Healthcheck (Healthchecks.io-kompatibel)
    |--------------------------------------------------------------------------
    |
    | Dead-Man-Switch für die DSGVO-getriebenen Cleanup-Schedules in
    | `routes/console.php`. Ohne externes Monitoring fallen ausgefallene
    | Cron-Jobs (Retention-Cleanup, pending-email-Cleanup) erst Wochen
    | später auf — diese Konfiguration ermöglicht das aktive Heartbeat-
    | Signal.
    |
    | Slug-pro-Job, ein gemeinsamer Ping-Key: Healthchecks.io kennt
    | Auto-Provisioning über `https://hc-ping.com/<ping-key>/<slug>` —
    | beim ersten Ping mit neuem Slug wird der Check angelegt. Die
    | erwartete Frequenz wird einmalig im Dashboard gesetzt.
    |
    | Ist `HEALTHCHECKS_PING_KEY` nicht gesetzt oder leer, überspringt
    | der Pinger sämtliche HTTP-Calls (relevant für local/testing —
    | Tests sollen keine Pings an die echte UUID schicken).
    */

    'base_url' => env('HEALTHCHECKS_BASE_URL', 'https://hc-ping.com'),

    'ping_key' => env('HEALTHCHECKS_PING_KEY'),

    'timeout_seconds' => 5,
];
