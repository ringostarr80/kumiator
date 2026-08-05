<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * `focus-ring` zeichnet über `focus-visible` und bleibt damit der Tastatur vorbehalten. Eine Farbe
 * unter `focus:` greift dagegen bei jedem Fokus und bleibt nach einem Mausklick stehen, bis er
 * weiterwandert — der angeklickte Menüeintrag sieht dann dauerhaft überfahren aus. Den Wechsel
 * zeigen `hover:` und `active:`.
 *
 * `outline` und `ring` fehlen in der Familienliste, weil sie keine Fläche einfärben, sondern den
 * Fokusring selbst zeichnen; den führt ein eigener Guard. Die geteilte Fokusfarbe fällt von selbst
 * heraus — sie heißt `focus` und trägt keine Zahl, `focus:border-focus` an den Formularfeldern
 * passiert das Muster also ohne Ausnahmeeintrag.
 */
final class FocusVariantsUseOnlyTheFocusColorTest extends TestCase
{
    /** Ein Farb-Utility, z. B. `bg-gray-700` aus `dark:focus:bg-gray-700`. */
    private const COLOR_UTILITY_PATTERN = '#^(text|bg|border|divide|placeholder|stroke)-'
        . '([a-z]+-\d{2,3}|white|black)(?:/\d+)?$#';

    public function testFocusVariantsUseOnlyTheFocusColor(): void
    {
        $violations = [];

        foreach (BladeViews::lines() as $line) {
            foreach (BladeViews::classStrings($line['content']) as $classString) {
                foreach ($this->foreignColorsUnderFocus($classString) as $token) {
                    $violations[] = sprintf('%s:%d %s', $line['path'], $line['number'], $token);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Diese Farben unter `focus:` bleiben nach einem Mausklick stehen, bis der Fokus '
            . 'weiterwandert. Den Wechsel stattdessen an `hover:` und `active:` hängen — den Fokus '
            . 'selbst zeigt `focus-ring`.',
        );
    }

    /**
     * @return list<string>
     */
    private function foreignColorsUnderFocus(string $classString): array
    {
        $tokens = [];

        foreach (BladeViews::utilities($classString) as $utility) {
            if (!in_array('focus', $utility['variants'], true)) {
                continue;
            }

            if (preg_match(self::COLOR_UTILITY_PATTERN, $utility['utility']) !== 1) {
                continue;
            }

            $tokens[] = $utility['token'];
        }

        return $tokens;
    }
}
