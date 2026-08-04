<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * Der Theme-Switcher schaltet `.dark` auf `<html>`, weshalb jede Graustufe ein Gegenstück für den
 * dunklen Modus braucht. Fehlt es, bleibt dunkler Text auf dunkler Fläche stehen — beim Entwickeln im
 * hellen Modus fällt das niemandem auf, weil die Seite dort korrekt aussieht.
 *
 * Geprüft wird die gray-Skala über alle Farbfamilien, die eine Fläche vom Untergrund absetzen: Text,
 * Flächen, Ränder, Trennlinien, Ringe, Platzhalter und Icon-Striche. Akzentfarben bleiben in beiden
 * Modi absichtlich identisch. Weiß und Schwarz zählen nur als Fläche, weil sie sonst dort anschlagen,
 * wo der Modus nichts ändert: `text-white` sitzt auf eingefärbtem Grund, `ring-black/5` ist ein
 * Schattensaum. Die Prüfung ist zeilenlokal, das Gegenstück muss also im selben Klassen-String stehen
 * wie die Ausgangsklasse.
 */
final class DarkModeVariantsAreCompleteTest extends TestCase
{
    /**
     * Ausnahmen je Blade-Datei relativ zu `resources/views`, mit der Zahl der gedeckten Stellen: Eine
     * weitere ist ein neuer Fall und soll auffallen, statt vom berechtigten Nachbarn mitgedeckt zu
     * werden.
     *
     * `components/banner.blade.php` und `components/modal.blade.php` legen ihre Graufläche über eine
     * eingefärbte bzw. abgedunkelte Ebene und sehen in beiden Modi gleich aus. Der Klartext-Token in
     * `api/api-token-manager.blade.php` sitzt auf einem `<x-input>`, dessen Komponente die
     * dark:-Varianten selbst mitbringt und per CSS-Reihenfolge gewinnt. Die weiße Fläche in
     * `profile/two-factor-authentication-form.blade.php` unterlegt den QR-Code: Ohne hellen Grund
     * scheitert das Einscannen. Die Icon-Striche in `components/welcome.blade.php` stehen dunkel
     * besser da als hell (5,6:1 gegen `gray-800`, 2,6:1 gegen Weiß) — eine dark:-Variante würde den
     * schwächeren der beiden Fälle nicht anfassen. Alle übrigen Einträge sind offene
     * Kontrast-Schwächen und verschwinden hier, sobald die betroffene Datei nachgezogen ist.
     */
    private const ALLOWED_WITHOUT_DARK_VARIANT = [
        'api/api-token-manager.blade.php' => ['bg-gray-100' => 1, 'text-gray-400' => 2, 'text-gray-500' => 1],
        'components/banner.blade.php' => ['bg-gray-500' => 1],
        'components/modal.blade.php' => ['bg-gray-500' => 1],
        'components/welcome.blade.php' => ['stroke-gray-400' => 4],
        'navigation-menu.blade.php' => ['text-gray-400' => 5],
        'profile/two-factor-authentication-form.blade.php' => ['bg-white' => 1],
    ];

    /** Ein Farb-Utility, z. B. `bg-gray-700` aus `dark:hover:bg-gray-700`. */
    private const COLOR_UTILITY_PATTERN = '#^(text|bg|border|divide|ring|placeholder|stroke)-'
        . '([a-z]+-\d{2,3}|white|black)(?:/\d+)?$#';

    public function testGrayUtilitiesHaveDarkCounterpart(): void
    {
        $violations = [];

        foreach ($this->findingsByToken() as $path => $tokens) {
            foreach ($tokens as $token => $lines) {
                $allowed = self::ALLOWED_WITHOUT_DARK_VARIANT[$path][$token] ?? 0;

                if (count($lines) <= $allowed) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s %s: %d Stellen (%s), gedeckt sind %d',
                    $path,
                    $token,
                    count($lines),
                    implode(',', $lines),
                    $allowed,
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Diese gray-Utilities haben kein dark:-Gegenstück im selben Klassen-String. Entweder eine '
            . 'passende dark:-Variante ergänzen oder den Zähler in ALLOWED_WITHOUT_DARK_VARIANT '
            . 'begründet erhöhen.',
        );
    }

    /**
     * Eine Ausnahme, die mehr Stellen deckt als es gibt, deckt eine spätere Regression stillschweigend
     * wieder zu.
     */
    public function testAllowListHasNoStaleEntries(): void
    {
        $found = $this->findingsByToken();

        $stale = [];

        foreach (self::ALLOWED_WITHOUT_DARK_VARIANT as $path => $tokens) {
            foreach ($tokens as $token => $allowed) {
                $count = count($found[$path][$token] ?? []);

                if ($count >= $allowed) {
                    continue;
                }

                $stale[] = sprintf('%s %s: %d gedeckt, %d gefunden', $path, $token, $allowed, $count);
            }
        }

        $this->assertSame(
            [],
            $stale,
            'Diese Einträge in ALLOWED_WITHOUT_DARK_VARIANT decken mehr Stellen als es gibt — die '
            . 'Klasse hat inzwischen ein dark:-Gegenstück oder kommt seltener vor. Zähler senken oder '
            . 'Eintrag entfernen.',
        );
    }

    /**
     * @return array<string, array<string, list<int>>>
     */
    private function findingsByToken(): array
    {
        $grouped = [];

        foreach ($this->scanViews() as $finding) {
            $grouped[$finding['path']][$finding['token']][] = $finding['line'];
        }

        return $grouped;
    }

    /**
     * @return list<array{path: string, line: int, token: string}>
     */
    private function scanViews(): array
    {
        $findings = [];

        foreach (BladeViews::lines() as $line) {
            foreach (BladeViews::classStrings($line['content']) as $classString) {
                foreach ($this->missingDarkVariants($classString) as $token) {
                    $findings[] = [
                        'path' => $line['path'],
                        'line' => $line['number'],
                        'token' => $token,
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function missingDarkVariants(string $classString): array
    {
        $utilities = $this->colorUtilities($classString);

        $missing = [];

        foreach ($utilities as $utility) {
            $switchesWithMode = str_starts_with($utility['color'], 'gray-')
                || ($utility['family'] === 'bg' && in_array($utility['color'], ['white', 'black'], true));

            if (!$switchesWithMode || in_array('dark', $utility['variants'], true)) {
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

        foreach (BladeViews::utilities($classString) as $utility) {
            if (preg_match(self::COLOR_UTILITY_PATTERN, $utility['utility'], $matches) !== 1) {
                continue;
            }

            $utilities[] = [
                'token' => $utility['token'],
                'variants' => $utility['variants'],
                'family' => $matches[1],
                'color' => $matches[2],
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
}
