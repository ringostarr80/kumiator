<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataTransferObjects\UploadLimitData;
use Tests\TestCase;

final class UploadLimitDataTest extends TestCase
{
    /**
     * Laravels `max`-Regel misst Dateien in Einheiten zu 1 024 Byte. Mit einer anderen Einheit ließe
     * die Regel mehr oder weniger durch, als das Formular anzeigt.
     */
    public function testKilobytesMatchTheUnitOfTheMaxRule(): void
    {
        $limit = new UploadLimitData(bytes: 2_097_152, constrainedByServer: false);

        $this->assertSame(2_048, $limit->kilobytes());
    }
}
