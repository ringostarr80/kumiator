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

            $buffer = null;
            $number = 0;

            foreach ($lines as $index => $content) {
                if ($buffer === null) {
                    $buffer = $content;
                    $number = $index + 1;
                } else {
                    // Das Leerzeichen trennt die Tokens, wo die Fortsetzung ohne Einrückung beginnt.
                    $buffer .= ' ' . $content;
                }

                // Ein Attribut über mehrere Zeilen ließe beide Hälften ohne Gegenstück zurück: Auf
                // keiner davon stünde ein vollständiges Literal, die Klassen dazwischen entfielen.
                if (substr_count($buffer, '"') % 2 === 1) {
                    continue;
                }

                yield [
                    'path' => $file->getRelativePathname(),
                    'number' => $number,
                    'content' => $buffer,
                ];

                $buffer = null;
            }

            // Schließt eine Datei ihr letztes Attribut nicht mehr, fielen sonst alle Zeilen ab dessen
            // Beginn ersatzlos aus jeder Prüfung.
            if ($buffer === null) {
                continue;
            }

            yield [
                'path' => $file->getRelativePathname(),
                'number' => $number,
                'content' => $buffer,
            ];
        }
    }

    /**
     * Klassenlisten stehen mal im `class`-Attribut, mal in einem Blade-Ausdruck (`@php`, `@props`,
     * Ternary-Zweig). Statt jede Schreibweise einzeln zu erfassen, gilt jedes Stringliteral als
     * Kandidat — solche ohne passende Utility fallen bei der Auswertung ohnehin durch.
     *
     * Alpine schreibt seine Klassenliste im Attribut noch einmal in Anführungszeichen
     * (`:class="{ 'bg-gray-100': open }"`), daher die zweite Ebene.
     *
     * @return list<string>
     */
    public static function classStrings(string $line): array
    {
        $literals = self::stringLiterals($line);

        $nested = [];

        foreach ($literals as $literal) {
            $nested = [...$nested, ...self::stringLiterals($literal)];
        }

        return [...$literals, ...$nested];
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

    /**
     * Beide Anführungszeichen in einem Durchlauf und von links gelesen: Getrennt gesucht zählte ein
     * Apostroph im Text (`title="wenn's passt"`) als Begrenzer und verschöbe jedes weitere Paar.
     *
     * @return list<string>
     */
    private static function stringLiterals(string $text): array
    {
        preg_match_all('/(["\'])(.*?)\1/', $text, $matches, PREG_SET_ORDER);

        $literals = [];

        foreach ($matches as $match) {
            $literals[] = $match[2];
        }

        return $literals;
    }
}
