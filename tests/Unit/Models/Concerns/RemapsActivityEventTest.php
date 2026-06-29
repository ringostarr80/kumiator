<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Concerns;

use App\Enums\ActivityChannel;
use App\Models\Activity;
use App\Models\User;
use Tests\TestCase;

final class RemapsActivityEventTest extends TestCase
{
    /**
     * Die `event`-Spalte ist nullable; ein Eintrag im eigenen Channel ohne
     * string-`event` muss unverändert durchlaufen, statt das string-Mapping zu
     * erreichen. Der Guard ist zugleich die Typ-Verengung, die `mapActivityEvent`
     * voraussetzt.
     */
    public function testActivityWithoutStringEventIsLeftUntouched(): void
    {
        $activity = new Activity();
        $activity->log_name = ActivityChannel::USER->value;
        $activity->event = null;

        User::applyEventLabelToActivity($activity);

        $this->assertNull($activity->event);
    }
}
