<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Der Theme-Switcher schaltet `.dark` auf `<html>`, weshalb jede Graustufe für Text und Flächen ein
 * Gegenstück für den dunklen Modus braucht. Fehlt es, bleibt dunkler Text auf dunkler Fläche stehen —
 * beim Entwickeln im hellen Modus fällt das niemandem auf, weil die Seite dort korrekt aussieht.
 *
 * Geprüft wird ausschließlich die gray-Skala: Sie trägt die Modus-Umschaltung, während Akzentfarben
 * in beiden Modi absichtlich identisch bleiben. Die Prüfung ist zeilenlokal, das Gegenstück muss also
 * im selben Klassen-String stehen wie die Ausgangsklasse.
 */
final class DarkModeVariantsAreCompleteTest extends TestCase
{
    /**
     * Ausnahmen je Blade-Datei relativ zu `resources/views`.
     *
     * `components/banner.blade.php` und `components/modal.blade.php` legen ihre Graufläche über eine
     * eingefärbte bzw. abgedunkelte Ebene und sehen in beiden Modi gleich aus. Der Klartext-Token in
     * `api/api-token-manager.blade.php` sitzt auf einem `<x-input>`, dessen Komponente die
     * dark:-Varianten selbst mitbringt und per CSS-Reihenfolge gewinnt. Alle übrigen Einträge sind
     * offene Kontrast-Schwächen und verschwinden hier, sobald die betroffene Datei nachgezogen ist.
     */
    private const ALLOWED_WITHOUT_DARK_VARIANT = [
        'api/api-token-manager.blade.php' => ['bg-gray-100', 'text-gray-400', 'text-gray-500'],
        'components/banner.blade.php' => ['bg-gray-500'],
        'components/modal.blade.php' => ['bg-gray-500'],
        'navigation-menu.blade.php' => ['text-gray-400'],
        'policy.blade.php' => ['bg-gray-100'],
        'terms.blade.php' => ['bg-gray-100'],
    ];

    /** Ein Farb-Utility samt vorangestellter Varianten, z. B. `dark:hover:bg-gray-700`. */
    private const COLOR_UTILITY_PATTERN = '#^((?:[a-z0-9-]+:)*)(text|bg)-([a-z]+-\d{2,3}|white|black)(?:/\d+)?$#';

    public function testGrayUtilitiesHaveDarkCounterpart(): void
    {
        $violations = [];

        foreach ($this->scanViews() as $finding) {
            $allowed = self::ALLOWED_WITHOUT_DARK_VARIANT[$finding['path']] ?? [];

            if (in_array($finding['token'], $allowed, true)) {
                continue;
            }

            $violations[] = sprintf('%s:%d %s', $finding['path'], $finding['line'], $finding['token']);
        }

        $this->assertSame(
            [],
            $violations,
            'Diese gray-Utilities haben kein dark:-Gegenstück im selben Klassen-String. Entweder eine '
            . 'passende dark:-Variante ergänzen oder den Eintrag begründet in '
            . 'ALLOWED_WITHOUT_DARK_VARIANT aufnehmen.',
        );
    }

    /**
     * Eine Ausnahme, deren Klasse längst eine dark:-Variante hat, deckt eine spätere Regression
     * stillschweigend wieder zu.
     */
    public function testAllowListHasNoStaleEntries(): void
    {
        $found = array_map(
            static fn (array $finding): string => $finding['path'] . ' ' . $finding['token'],
            $this->scanViews(),
        );

        $stale = [];

        foreach (self::ALLOWED_WITHOUT_DARK_VARIANT as $path => $tokens) {
            foreach ($tokens as $token) {
                if (in_array($path . ' ' . $token, $found, true)) {
                    continue;
                }

                $stale[] = $path . ' ' . $token;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'Diese Einträge in ALLOWED_WITHOUT_DARK_VARIANT sind gegenstandslos — die Klasse hat '
            . 'inzwischen ein dark:-Gegenstück oder kommt in der Datei nicht mehr vor. Bitte entfernen.',
        );
    }

    /**
     * @return list<array{path: string, line: int, token: string}>
     */
    private function scanViews(): array
    {
        $findings = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                foreach ($this->classStrings($line) as $classString) {
                    foreach ($this->missingDarkVariants($classString) as $token) {
                        $findings[] = [
                            'path' => $file->getRelativePathname(),
                            'line' => $index + 1,
                            'token' => $token,
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Klassenlisten stehen mal im `class`-Attribut, mal in einem Blade-Ausdruck (`@php`, `@props`,
     * Ternary-Zweig). Statt jede Schreibweise einzeln zu erfassen, gilt jedes Stringliteral als
     * Kandidat — solche ohne Farb-Utility fallen bei der Auswertung ohnehin durch.
     *
     * @return list<string>
     */
    private function classStrings(string $line): array
    {
        preg_match_all('/"([^"]*)"/', $line, $doubleQuoted);
        preg_match_all("/'([^']*)'/", $line, $singleQuoted);

        return [...$doubleQuoted[1], ...$singleQuoted[1]];
    }

    /**
     * @return list<string>
     */
    private function missingDarkVariants(string $classString): array
    {
        $utilities = $this->colorUtilities($classString);

        $missing = [];

        foreach ($utilities as $utility) {
            if (!str_starts_with($utility['color'], 'gray-') || in_array('dark', $utility['variants'], true)) {
                continue;
            }

            if ($this->hasDarkCounterpart($utility, $utilities)) {
                continue;
            }

            $missing[] = $utility['token'];
        }

        return $missing;
    }

    /**
     * @return list<array{token: string, variants: list<string>, family: string, color: string}>
     */
    private function colorUtilities(string $classString): array
    {
        $utilities = [];

        foreach (preg_split('/\s+/', trim($classString)) ?: [] as $token) {
            if (preg_match(self::COLOR_UTILITY_PATTERN, $token, $matches) !== 1) {
                continue;
            }

            $utilities[] = [
                'token' => $token,
                'variants' => $this->variants($matches[1]),
                'family' => $matches[2],
                'color' => $matches[3],
            ];
        }

        return $utilities;
    }

    /**
     * Das Gegenstück muss dieselben Varianten tragen wie die Ausgangsklasse plus `dark`, sonst greift
     * es in einem anderen Zustand: `hover:bg-gray-50` wird von `dark:bg-gray-700` nicht abgedeckt.
     *
     * @param array{token: string, variants: list<string>, family: string, color: string} $utility
     * @param list<array{token: string, variants: list<string>, family: string, color: string}> $candidates
     */
    private function hasDarkCounterpart(array $utility, array $candidates): bool
    {
        $expected = [...$utility['variants'], 'dark'];
        sort($expected);

        foreach ($candidates as $candidate) {
            $variants = $candidate['variants'];
            sort($variants);

            if ($candidate['family'] === $utility['family'] && $variants === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function variants(string $prefix): array
    {
        return array_values(array_filter(explode(':', $prefix)));
    }
}
