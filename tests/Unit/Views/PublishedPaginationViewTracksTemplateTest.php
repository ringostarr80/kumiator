<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\TestCase;

/**
 * `resources/views/vendor/livewire/tailwind.blade.php` ist eine Kopie: Verbessert Livewire seine
 * Vorlage, kommt davon nichts mehr an, und niemand würde es bemerken.
 * `fixtures/livewire-pagination-tailwind.blade.php` hält die Fassung fest, auf der die Kopie beruht, damit
 * der Diff beim Fehlschlag Livewires Änderung zeigt. Übernehmen per Dreiwege-Merge, Konflikte auflösen, den
 * Kopfkommentar der Kopie prüfen, dann die Basis nachziehen:
 *
 *     git merge-file resources/views/vendor/livewire/tailwind.blade.php \
 *         tests/Unit/Views/fixtures/livewire-pagination-tailwind.blade.php \
 *         vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php
 *     cp vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php \
 *         tests/Unit/Views/fixtures/livewire-pagination-tailwind.blade.php
 */
final class PublishedPaginationViewTracksTemplateTest extends TestCase
{
    private const TEMPLATE = 'vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php';

    private const BASELINE = __DIR__ . '/fixtures/livewire-pagination-tailwind.blade.php';

    public function testTemplateIsUnchanged(): void
    {
        $this->assertFileEquals(
            self::BASELINE,
            base_path(self::TEMPLATE),
            'Livewire hat seine Paginationsansicht geändert — die publizierte Kopie hinkt hinterher.',
        );
    }
}
