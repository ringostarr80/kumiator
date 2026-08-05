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
     * Ausnahmen je Blade-Datei relativ zu `resources/views`, mit der Zahl der gedeckten Stellen: Eine
     * weitere ist ein neuer Fall und soll auffallen, statt von der berechtigten mitgedeckt zu werden.
     *
     * `components/banner.blade.php` färbt seinen Ring je nach Bannerfarbe per Alpine weiß oder
     * dunkelgrau; die feste Indigo-Farbe des Utilities träfe dort auf zu wenig Kontrast. Gedeckt sind
     * die beiden Klassen der Ringgeometrie und die beiden gebundenen Farben.
     */
    private const ALLOWED_WITH_RAW_OUTLINE = [
        'components/banner.blade.php' => 4,
    ];

    /**
     * Als ausgeschriebener Ring gilt jede `outline`- oder `ring`-Klasse unter einer Fokus-Variante,
     * unabhängig von deren Schreibweise und vorangestellten Präfixen — sonst rutscht durch, wer den
     * Ring einmal anders formuliert als der Guard ihn kennt. Zwei Nachbarn bleiben bewusst außen vor:
     * `focus:ring-focus` an den Formularfeldern nennt allein die geteilte Farbe, die Ringgeometrie
     * zeichnet dort `@tailwindcss/forms`, und `dark:focus:ring-offset-gray-800` an der Checkbox
     * richtet sich nach dem Hintergrund statt nach der Fokusfarbe.
     */
    private const RAW_FOCUS_RING_PATTERN =
        '/focus(?:-visible|-within)?:(?!ring-focus|ring-offset-[a-z])-?(?:outline|ring)(?![a-z])/';

    public function testViewsUseTheSharedFocusRingUtility(): void
    {
        $found = array_fill_keys(array_keys(self::ALLOWED_WITH_RAW_OUTLINE), []);

        foreach (BladeViews::lines() as $line) {
            $occurrences = preg_match_all(self::RAW_FOCUS_RING_PATTERN, $line['content']);

            for ($index = 0; $index < $occurrences; $index++) {
                $found[$line['path']][] = $line['number'];
            }
        }

        $violations = [];

        foreach ($found as $path => $lines) {
            $allowed = self::ALLOWED_WITH_RAW_OUTLINE[$path] ?? 0;

            if (count($lines) === $allowed) {
                continue;
            }

            $violations[] = sprintf(
                '%s: %d Stellen (%s), gedeckt sind %d',
                $path,
                count($lines),
                implode(',', $lines) ?: 'keine',
                $allowed,
            );
        }

        $this->assertSame(
            [],
            $violations,
            'Diese Dateien schreiben den Fokusring öfter oder seltener als Klassenliste aus, als der '
            . 'Eintrag deckt. Stattdessen `focus-ring` bzw. `focus-ring-inset` verwenden — oder den '
            . 'Zähler in ALLOWED_WITH_RAW_OUTLINE begründet anpassen.',
        );
    }
}
