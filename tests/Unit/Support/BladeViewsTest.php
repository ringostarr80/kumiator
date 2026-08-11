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
    /**
     * Die Nummerierung zählt je Datei, nicht über alle zusammen: In der Gesamtmenge steckt die 1 auch
     * dann, wenn allein die erste Datei bei 1 beginnt — die Verstoßmeldungen der Guards zeigten dann auf
     * Zeilen, die es in der genannten Datei nicht gibt.
     *
     * Lückenlos ist die Folge nicht: Ein Attribut über mehrere Zeilen wird unter seiner ersten gemeldet,
     * die Fortsetzung bekommt keine eigene Nummer.
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
            $ascending = array_values(array_unique($numbers));
            sort($ascending);

            if ($numbers[0] === 1 && $numbers === $ascending) {
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
        $directory = sys_get_temp_dir() . '/' . uniqid('blade-views-', true);
        File::makeDirectory($directory);
        File::put($directory . '/leer.blade.php', '');
        File::put($directory . '/gefuellt.blade.php', "<div></div>\n");

        try {
            $covered = [];

            foreach (BladeViews::lines($directory) as $line) {
                $covered[$line['path']] = true;
            }

            $this->assertSame(['gefuellt.blade.php'], array_keys($covered));
            $this->assertSame(['gefuellt.blade.php'], $this->bladeFiles($directory));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function testClassStringsCollectsEveryQuotedLiteral(): void
    {
        $line = '<a class="px-3 py-2">@php $classes = \'text-sm\'; @endphp';

        $this->assertSame(['px-3 py-2', 'text-sm'], BladeViews::classStrings($line));
        $this->assertSame([], BladeViews::classStrings('<a>ohne Literal</a>'));
    }

    /**
     * Der Apostroph im Text steht mitten in einem Attribut und begrenzt dort nichts. Zählte er als
     * Begrenzer, verschöbe sich jedes weitere Paar um eine Stelle — an der Klassenliste käme dann ein
     * Textfetzen heraus, und die Guards sähen die Klassen nie.
     */
    public function testClassStringsSurviveAnApostropheInProse(): void
    {
        $line = '<button title="Speichern, wenn\'s passt" :class="{ \'bg-gray-100\': open }">';

        $this->assertSame(
            ['Speichern, wenn\'s passt', '{ \'bg-gray-100\': open }', 'bg-gray-100'],
            BladeViews::classStrings($line),
        );
    }

    /**
     * Ein umbrochenes Attribut lässt beide Hälften ohne Gegenstück zurück: Auf keiner der Zeilen steht
     * dann ein vollständiges Literal, und alle Klassen dazwischen fallen aus jeder Prüfung heraus.
     */
    public function testLinesJoinAnAttributeThatSpansSeveralLines(): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('blade-views-', true);
        File::makeDirectory($directory);
        File::put($directory . '/umbrochen.blade.php', "<button class=\"bg-gray-100\n    text-gray-500\">\n");

        try {
            $tokens = [];

            foreach (BladeViews::lines($directory) as $line) {
                foreach (BladeViews::classStrings($line['content']) as $classString) {
                    foreach (BladeViews::utilities($classString) as $utility) {
                        $tokens[$line['number']][] = $utility['token'];
                    }
                }
            }

            $this->assertSame([1 => ['bg-gray-100', 'text-gray-500']], $tokens);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Endet eine Datei mitten im Attribut, gibt es keine Zeile mehr, die es schließt. Ohne die Ausgabe
     * am Dateiende verschwände alles ab dessen Beginn ersatzlos aus jeder Prüfung.
     */
    public function testLinesYieldAnAttributeThatNeverCloses(): void
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('blade-views-', true);
        File::makeDirectory($directory);
        File::put($directory . '/abgeschnitten.blade.php', "<span>davor</span>\n<button class=\"bg-gray-100\n");

        try {
            $contents = [];

            foreach (BladeViews::lines($directory) as $line) {
                $contents[$line['number']] = $line['content'];
            }

            $this->assertSame([1 => '<span>davor</span>', 2 => '<button class="bg-gray-100'], $contents);
        } finally {
            File::deleteDirectory($directory);
        }
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
    private function bladeFiles(?string $directory = null): array
    {
        $paths = [];

        foreach (File::allFiles($directory ?? resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php') || $file->getSize() === 0) {
                continue;
            }

            $paths[] = $file->getRelativePathname();
        }

        sort($paths);

        return $paths;
    }
}
