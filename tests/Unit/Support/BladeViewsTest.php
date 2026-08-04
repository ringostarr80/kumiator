<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\File;
use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * Liefert der Durchlauf keine Zeilen, melden sämtliche Guards unter `tests/Unit/Views` grün, ohne je
 * eine Datei gesehen zu haben.
 */
final class BladeViewsTest extends TestCase
{
    public function testLinesCoverEveryBladeFileWithOneBasedNumbers(): void
    {
        $lines = iterator_to_array(BladeViews::lines(), false);

        $paths = array_values(array_unique(array_column($lines, 'path')));
        sort($paths);

        $numbers = array_column($lines, 'number');

        $this->assertSame($this->bladeFiles(), $paths);
        $this->assertContains(1, $numbers);
        $this->assertNotContains(0, $numbers);
    }

    public function testClassStringsCollectsEveryQuotedLiteral(): void
    {
        $line = '<a class="px-3 py-2">@php $classes = \'text-sm\'; @endphp';

        $this->assertSame(['px-3 py-2', 'text-sm'], BladeViews::classStrings($line));
        $this->assertSame([], BladeViews::classStrings('<a>ohne Literal</a>'));
    }

    public function testUtilitiesSplitVariantsFromTheUtility(): void
    {
        $this->assertSame(
            [
                ['token' => 'bg-white', 'variants' => [], 'utility' => 'bg-white'],
                ['token' => 'dark:hover:bg-gray-700', 'variants' => ['dark', 'hover'], 'utility' => 'bg-gray-700'],
            ],
            BladeViews::utilities('  bg-white dark:hover:bg-gray-700 '),
        );
        $this->assertSame([], BladeViews::utilities('   '));
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $paths = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $paths[] = $file->getRelativePathname();
        }

        sort($paths);

        return $paths;
    }
}
