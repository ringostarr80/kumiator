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
     * Als ausgeschriebener Ring gelten `focus-visible:outline-*`, `focus:outline-none` sowie eine
     * numerische Ringbreite oder -farbe. Zwei Nachbarn bleiben bewusst außen vor:
     * `dark:focus:ring-offset-gray-800` an der Checkbox richtet sich nach dem Hintergrund statt nach
     * der Fokusfarbe, und `focus:border-*` färbt an den Navigationslinks deren Zustandsrand um.
     */
    private const RAW_FOCUS_RING_PATTERN =
        '/focus-visible:-?outline-|focus:outline-none|focus:ring-(?:[a-z]+-)?\d/';

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
