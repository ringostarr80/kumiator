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
     * Das Verzeichnis ist wählbar, damit ein Test seine Dateien nicht im echten `views` anlegen muss:
     * Die läsen bei parallelem Lauf die Guards der anderen Prozesse mit.
     *
     * @return Generator<int, array{path: string, number: int, content: string}, mixed, void>
     */
    public static function lines(?string $directory = null): Generator
    {
        foreach (File::allFiles($directory ?? resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Der Fallback hält allein das `foreach` typsicher — ein Lesefehler kommt hier nie an,
            // den macht Laravels Error-Handler vorher zur ErrorException. Übrig bleibt die leere Datei.
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

    /**
     * Varianten stehen bei jeder Utility gleich davor und gleich geschrieben (`dark:hover:bg-gray-700`);
     * erst danach unterscheiden sich die Guards, weil jeder eine andere Familie prüft. Wer nur den
     * eigenen Teil selbst mitbringt, kopiert die Zerlegung nicht erneut.
     *
     * @return list<array{token: string, variants: list<string>, utility: string}>
     */
    public static function utilities(string $classString): array
    {
        $utilities = [];

        foreach (preg_split('/\s+/', trim($classString)) ?: [] as $token) {
            if (preg_match('#^((?:[a-z0-9-]+:)*)(.+)$#', $token, $matches) !== 1) {
                continue;
            }

            $utilities[] = [
                'token' => $token,
                'variants' => array_values(array_filter(explode(':', $matches[1]))),
                'utility' => $matches[2],
            ];
        }

        return $utilities;
    }
}
