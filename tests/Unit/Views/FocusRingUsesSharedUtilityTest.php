<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * Der Tastaturfokus hängt an den Utilities `focus-ring` und `focus-ring-inset` aus
 * `resources/css/app.css`. Wird er stattdessen wieder als Klassenliste ausgeschrieben, geht eine
 * Änderung an Farbe, Breite oder Offset an genau dieser Stelle vorbei — auffallen würde das erst
 * jemandem, der die Seite per Tastatur bedient.
 */
final class FocusRingUsesSharedUtilityTest extends TestCase
{
    /**
     * Ausnahmen je Blade-Datei relativ zu `resources/views`.
     *
     * `components/banner.blade.php` färbt seinen Ring je nach Bannerfarbe per Alpine weiß oder
     * dunkelgrau; die feste Indigo-Farbe des Utilities träfe dort auf zu wenig Kontrast.
     */
    private const ALLOWED_WITH_RAW_OUTLINE = [
        'components/banner.blade.php',
    ];

    /**
     * Als ausgeschriebener Ring gilt jede `outline`- oder `ring`-Klasse unter einer Fokus-Variante,
     * unabhängig von deren Schreibweise und vorangestellten Präfixen — sonst rutscht durch, wer den
     * Ring einmal anders formuliert als der Guard ihn kennt. Zwei Nachbarn bleiben bewusst außen vor:
     * `focus:ring-focus` an den Formularfeldern nennt allein die geteilte Farbe, die Ringgeometrie
     * zeichnet dort `@tailwindcss/forms`, und `dark:focus:ring-offset-gray-800` an der Checkbox
     * richtet sich nach dem Hintergrund statt nach der Fokusfarbe. `focus:border-*` an den
     * Navigationslinks färbt deren Zustandsrand um und gehört keiner der beiden Familien an.
     */
    private const RAW_FOCUS_RING_PATTERN =
        '/focus(?:-visible|-within)?:(?!ring-focus|ring-offset-[a-z])-?(?:outline|ring)-/';

    public function testViewsUseTheSharedFocusRingUtility(): void
    {
        $violations = [];

        foreach (BladeViews::lines() as $line) {
            if (in_array($line['path'], self::ALLOWED_WITH_RAW_OUTLINE, true)) {
                continue;
            }

            if (preg_match(self::RAW_FOCUS_RING_PATTERN, $line['content']) !== 1) {
                continue;
            }

            $violations[] = sprintf('%s:%d', $line['path'], $line['number']);
        }

        $this->assertSame(
            [],
            $violations,
            'Diese Stellen schreiben den Fokusring als Klassenliste aus. Stattdessen `focus-ring` '
            . 'bzw. `focus-ring-inset` verwenden oder den Eintrag begründet in '
            . 'ALLOWED_WITH_RAW_OUTLINE aufnehmen.',
        );
    }
}
