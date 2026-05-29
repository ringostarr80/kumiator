<?php

declare(strict_types=1);

namespace App\Services\Schedule;

/**
 * Drei Phasen eines Schedule-Lauf-Heartbeats. URL-Suffix korrespondiert zur
 * Healthchecks.io-Konvention: `/start` markiert den Lauf-Beginn (Laufzeit-
 * Statistik), leeres Suffix erfolgreichen Abschluss, `/fail` einen Fehlschlag.
 */
enum HealthcheckPingPhase: string
{
    case Start = 'start';

    case Success = 'success';

    case Failure = 'failure';

    public function urlSuffix(): string
    {
        return match ($this) {
            self::Start => '/start',
            self::Success => '',
            self::Failure => '/fail',
        };
    }
}
