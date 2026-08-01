<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tailwind kapselt jede `hover:`-Utility in `@media (hover:hover)`, sodass sie auf Touch-Geräten nie
 * greift. Ein Bedienelement, dessen Flächenwechsel allein am Hover hängt, reagiert dort auf einen Tap
 * mit gar nichts — bei `wire:click` wirkt die App dadurch hängend, bis die Antwort eintrifft.
 *
 * Als Ersatz zählt `active:` oder `focus:`; `focus-visible` (und damit `focus-ring`) nicht, denn ein
 * Tap löst es nicht aus. Geprüft wird nur die bg-Familie: Reine Textfarbwechsel sitzen hier auf
 * unterstrichenen Links, die ihren Zustand ohnehin zeigen. Die Prüfung ist zeilenlokal, das
 * Gegenstück muss also im selben Klassen-String stehen wie die hover-Klasse.
 */
final class HoverBackgroundsHaveTouchCounterpartTest extends TestCase
{
    /** Eine bg-Utility mit mindestens einer vorangestellten Variante, z. B. `dark:hover:bg-gray-700`. */
    private const VARIANT_BACKGROUND_PATTERN = '#^((?:[a-z0-9-]+:)+)bg-[a-z]#';

    /** Zustände, die ein Tap auslöst und die den fehlenden Hover damit ersetzen. */
    private const TOUCH_STATES = ['active', 'focus'];

    public function testHoverBackgroundsHaveTouchCounterpart(): void
    {
        $violations = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                foreach ($this->classStrings($line) as $classString) {
                    foreach ($this->hoverWithoutTouchCounterpart($classString) as $token) {
                        $violations[] = sprintf('%s:%d %s', $file->getRelativePathname(), $index + 1, $token);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Diese hover-Flächen haben kein Gegenstück für Touch-Geräte. Eine `active:`-Klasse mit '
            . 'denselben übrigen Varianten in denselben Klassen-String aufnehmen.',
        );
    }

    /**
     * Klassenlisten stehen mal im `class`-Attribut, mal in einem Blade-Ausdruck (`@php`, `@props`,
     * Ternary-Zweig). Statt jede Schreibweise einzeln zu erfassen, gilt jedes Stringliteral als
     * Kandidat — solche ohne bg-Utility fallen bei der Auswertung ohnehin durch.
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
    private function hoverWithoutTouchCounterpart(string $classString): array
    {
        $backgrounds = $this->variantBackgrounds($classString);

        $missing = [];

        foreach ($backgrounds as $background) {
            if (!in_array('hover', $background['variants'], true)) {
                continue;
            }

            if ($this->hasTouchCounterpart($background['variants'], $backgrounds)) {
                continue;
            }

            $missing[] = $background['token'];
        }

        return $missing;
    }

    /**
     * Das Gegenstück muss dieselben übrigen Varianten tragen, sonst greift es in einem anderen
     * Kontext: `dark:hover:bg-gray-700` wird von `active:bg-gray-50` nicht abgedeckt. Die Farbe darf
     * abweichen — ein gedrückter Zustand wird üblicherweise dunkler gezeichnet als ein überfahrener.
     *
     * @param list<string> $hoverVariants
     * @param list<array{token: string, variants: list<string>}> $candidates
     */
    private function hasTouchCounterpart(array $hoverVariants, array $candidates): bool
    {
        $others = array_values(array_filter(
            $hoverVariants,
            static fn (string $variant): bool => $variant !== 'hover',
        ));

        foreach (self::TOUCH_STATES as $state) {
            $expected = [...$others, $state];
            sort($expected);

            foreach ($candidates as $candidate) {
                $variants = $candidate['variants'];
                sort($variants);

                if ($variants === $expected) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{token: string, variants: list<string>}>
     */
    private function variantBackgrounds(string $classString): array
    {
        $backgrounds = [];

        foreach (preg_split('/\s+/', trim($classString)) ?: [] as $token) {
            if (preg_match(self::VARIANT_BACKGROUND_PATTERN, $token, $matches) !== 1) {
                continue;
            }

            $backgrounds[] = [
                'token' => $token,
                'variants' => array_values(array_filter(explode(':', $matches[1]))),
            ];
        }

        return $backgrounds;
    }
}
