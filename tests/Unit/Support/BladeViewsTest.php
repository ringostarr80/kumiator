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
    private const string EMPTY_VIEW = 'empty-view-under-test.blade.php';

    /**
     * Die Nummerierung zählt je Datei, nicht über alle zusammen: In der Gesamtmenge steckt die 1 auch
     * dann, wenn allein die erste Datei bei 1 beginnt — die Verstoßmeldungen der Guards zeigten dann auf
     * Zeilen, die es in der genannten Datei nicht gibt.
     */
    public function testLinesCoverEveryBladeFileWithOneBasedNumbers(): void
    {
        $numbersByPath = [];

        foreach (BladeViews::lines() as $line) {
            $numbersByPath[$line['path']][] = $line['number'];
        }

        $paths = array_keys($numbersByPath);
        sort($paths);

        $misnumbered = [];

        foreach ($numbersByPath as $path => $numbers) {
            if ($numbers === range(1, count($numbers))) {
                continue;
            }

            $misnumbered[] = $path;
        }

        $this->assertSame($this->bladeFiles(), $paths);
        $this->assertSame([], $misnumbered);
    }

    /**
     * Eine Datei ohne Inhalt liefert keine Zeile. Zählte die Gegenprobe sie trotzdem als abzudecken,
     * meldete der Vergleich darüber einen Guard-Ausfall, wo es gar nichts zu prüfen gibt.
     */
    public function testEmptyBladeFilesStayOutOfTheComparison(): void
    {
        $path = resource_path('views/' . self::EMPTY_VIEW);
        File::put($path, '');

        try {
            $covered = [];

            foreach (BladeViews::lines() as $line) {
                $covered[$line['path']] = true;
            }

            $this->assertArrayNotHasKey(self::EMPTY_VIEW, $covered);
            $this->assertNotContains(self::EMPTY_VIEW, $this->bladeFiles());
        } finally {
            File::delete($path);
        }
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
     * Eine leere Datei bleibt draußen, weil sie keine Zeile liefert und der Durchlauf sie deshalb
     * zu Recht auslässt — mitgezählt verschöbe sie den Vergleich und meldete die halbe Liste als fehlend.
     *
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $paths = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php') || $file->getSize() === 0) {
                continue;
            }

            $paths[] = $file->getRelativePathname();
        }

        sort($paths);

        return $paths;
    }
}
