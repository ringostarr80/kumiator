<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * `resources/views/vendor/livewire/tailwind.blade.php` ist eine Kopie: Verbessert Livewire seine
 * Vorlage, kommt davon nichts mehr an, und niemand würde es bemerken. Schlägt der Hash an, gehören
 * beide Fassungen verglichen, die Änderung übernommen und der Wert hier nachgezogen.
 */
final class PublishedPaginationViewTracksTemplateTest extends TestCase
{
    private const TEMPLATE = 'vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php';

    private const TEMPLATE_HASH = 'a26a18c72b9d8dd072e76dbbdccfe8b48313ce7d22bee94a1261192c43743a6c';

    public function testTemplateIsUnchanged(): void
    {
        $this->assertSame(
            self::TEMPLATE_HASH,
            hash_file('sha256', base_path(self::TEMPLATE)),
            'Livewire hat seine Paginationsansicht geändert — die publizierte Kopie hinkt hinterher.',
        );
    }
}
