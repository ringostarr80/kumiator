<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * Tailwind kapselt jede `hover:`-Utility in `@media (hover:hover)`, sodass sie auf Touch-Geräten nie
 * greift. Ein Bedienelement, dessen Flächenwechsel allein am Hover hängt, reagiert dort auf einen Tap
 * mit gar nichts — bei `wire:click` wirkt die App dadurch hängend, bis die Antwort eintrifft.
 *
 * Als Ersatz zählt allein `active:`. Weder `focus:` noch `focus-visible:` (und damit `focus-ring`)
 * taugen dafür: Safari fokussiert Links und Buttons beim Tap nicht, ein `<a>` nur mit `tabindex`.
 * Geprüft wird nur die Fläche: An ihr liest sich der gedrückte Zustand ab, ein reiner
 * Textfarbwechsel trägt diese Rückmeldung nicht. Ob die Bedienelemente, die es dabei belassen, einen
 * Tap-Zustand brauchen, ist eine Gestaltungsentscheidung und bleibt hier offen. Die Prüfung ist
 * zeilenlokal, das Gegenstück muss also im selben Klassen-String stehen wie die hover-Klasse.
 */
final class HoverBackgroundsHaveTouchCounterpartTest extends TestCase
{
    /**
     * Eine eingefärbte Fläche, z. B. `bg-gray-700` aus `dark:hover:bg-gray-700`. Hinten verankert,
     * weil `bg-cover` oder `bg-clip-text` denselben Anfang tragen, ohne eine Fläche zu färben.
     */
    private const BACKGROUND_PATTERN = '#^bg-([a-z]+-\d{2,3}|white|black)(?:/\d+)?$#';

    /** Tailwind kapselt alle drei in dieselbe Media-Query; daneben steht, was ein Tap auslöst. */
    private const TOUCH_COUNTERPARTS = [
        'hover' => 'active',
        'group-hover' => 'group-active',
        'peer-hover' => 'peer-active',
    ];

    public function testHoverBackgroundsHaveTouchCounterpart(): void
    {
        $this->assertSame(
            [],
            $this->violations(),
            'Diese hover-Flächen haben kein Gegenstück für Touch-Geräte. Die zugehörige `active`-Variante '
            . 'mit denselben übrigen Varianten in denselben Klassen-String aufnehmen.',
        );
    }

    /**
     * Bliebe die Prüfung beim wörtlichen `hover`, ginge eine Fläche, die erst beim Überfahren der
     * Gruppe oder des Nachbarn erscheint, ungeprüft durch.
     */
    #[DataProvider('hoverVariants')]
    public function testEveryHoverVariantNeedsItsOwnTouchCounterpart(string $hover, string $touch): void
    {
        $hovered = $hover . ':bg-gray-700';
        $tapped = $touch . ':bg-gray-700';

        $this->assertSame(['probe.blade.php:1 ' . $hovered], $this->violationsFor($hovered));
        $this->assertSame([], $this->violationsFor($hovered . ' ' . $tapped));
    }

    /**
     * `bg-cover` und Verwandte teilen den Anfang mit den Flächenfarben, färben aber nichts ein — ein
     * Gegenstück für den Tap einzufordern verlangte etwas, das dort nichts zu zeigen hätte.
     */
    public function testBackgroundUtilitiesWithoutAColorStayOut(): void
    {
        $this->assertSame([], $this->violationsFor('hover:bg-cover hover:bg-clip-text hover:bg-linear-to-r'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function hoverVariants(): array
    {
        return [
            ['hover', 'active'],
            ['group-hover', 'group-active'],
            ['peer-hover', 'peer-active'],
        ];
    }

    /**
     * Ohne Argument gilt das echte Views-Verzeichnis; die eigenen Tests geben ihres mit, statt eine
     * Probe-Datei zwischen die geprüften Views zu legen.
     *
     * @return list<string>
     */
    private function violations(?string $directory = null): array
    {
        $violations = [];

        foreach (BladeViews::lines($directory) as $line) {
            foreach (BladeViews::classStrings($line['content']) as $classString) {
                foreach ($this->hoverWithoutTouchCounterpart($classString) as $token) {
                    $violations[] = sprintf('%s:%d %s', $line['path'], $line['number'], $token);
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function violationsFor(string $classString): array
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('touch-guard-', true);
        File::makeDirectory($directory);
        File::put($directory . '/probe.blade.php', sprintf('<button class="%s">', $classString));

        try {
            return $this->violations($directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function hoverWithoutTouchCounterpart(string $classString): array
    {
        $backgrounds = $this->variantBackgrounds($classString);

        $missing = [];

        foreach ($backgrounds as $background) {
            if (!$this->hasHoverVariant($background['variants'])) {
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
     * @param list<string> $variants
     */
    private function hasHoverVariant(array $variants): bool
    {
        return array_intersect($variants, array_keys(self::TOUCH_COUNTERPARTS)) !== [];
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
        $expected = array_map(
            static fn (string $variant): string => self::TOUCH_COUNTERPARTS[$variant] ?? $variant,
            $hoverVariants,
        );
        sort($expected);

        foreach ($candidates as $candidate) {
            $variants = $candidate['variants'];
            sort($variants);

            if ($variants === $expected) {
                return true;
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

        foreach (BladeViews::utilities($classString) as $utility) {
            if ($utility['variants'] === [] || preg_match(self::BACKGROUND_PATTERN, $utility['utility']) !== 1) {
                continue;
            }

            $backgrounds[] = [
                'token' => $utility['token'],
                'variants' => $utility['variants'],
            ];
        }

        return $backgrounds;
    }
}
