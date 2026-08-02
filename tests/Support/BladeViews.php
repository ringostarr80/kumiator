<?php

declare(strict_types=1);

namespace Tests\Support;

use Generator;
use Illuminate\Support\Facades\File;

/**
 * Die Guards unter `tests/Unit/Views` prüfen alle dieselben Blade-Dateien zeilenweise. Steht der
 * Durchlauf nur hier, kostet ein weiterer Guard keine erneute Kopie — und eine Erweiterung, etwa um
 * publizierte Vendor-Views, wirkt für alle zugleich.
 */
final class BladeViews
{
    /**
     * @return Generator<int, array{path: string, number: int, content: string}, mixed, void>
     */
    public static function lines(): Generator
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $content) {
                yield [
                    'path' => $file->getRelativePathname(),
                    'number' => $index + 1,
                    'content' => $content,
                ];
            }
        }
    }

    /**
     * Klassenlisten stehen mal im `class`-Attribut, mal in einem Blade-Ausdruck (`@php`, `@props`,
     * Ternary-Zweig). Statt jede Schreibweise einzeln zu erfassen, gilt jedes Stringliteral als
     * Kandidat — solche ohne passende Utility fallen bei der Auswertung ohnehin durch.
     *
     * @return list<string>
     */
    public static function classStrings(string $line): array
    {
        preg_match_all('/"([^"]*)"/', $line, $doubleQuoted);
        preg_match_all("/'([^']*)'/", $line, $singleQuoted);

        return [...$doubleQuoted[1], ...$singleQuoted[1]];
    }
}
