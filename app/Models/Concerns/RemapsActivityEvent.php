<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\ActivityEvent;
use Spatie\Activitylog\Models\Activity as ActivityModel;

/**
 * Hebt vor dem Insert das generische Eloquent-Event (`created`/`updated`/…)
 * auf den fachlichen Code des nutzenden Models an. Channel-Gate und
 * Mapping-Tabelle liefert das Model; das Guard-/Zuweisungs-Gerüst lebt hier
 * nur einmal.
 *
 * Die Verdrahtung über einen `Activity::saving`-Listener ist am Hook im
 * `AppServiceProvider` begründet.
 */
trait RemapsActivityEvent
{
    abstract protected static function activityRemapChannel(): string;

    /**
     * Dem Passkey-Mapping genügt der Event-Name; das User-Mapping braucht
     * zusätzlich den `attribute_changes`-Diff aus `$activity`, daher führt die
     * Signatur beides.
     */
    abstract protected static function mapActivityEvent(string $eventName, ActivityModel $activity): ?ActivityEvent;

    public static function applyEventLabelToActivity(ActivityModel $activity): void
    {
        if ($activity->log_name !== static::activityRemapChannel()) {
            return;
        }

        $event = $activity->event;

        if (!is_string($event)) {
            return;
        }

        $mapped = static::mapActivityEvent($event, $activity);

        if ($mapped === null) {
            return;
        }

        $activity->event = $mapped->value;
    }
}
