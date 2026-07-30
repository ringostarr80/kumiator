<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\File;
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

    public function testViewsUseTheSharedFocusRingUtility(): void
    {
        $violations = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (in_array($file->getRelativePathname(), self::ALLOWED_WITH_RAW_OUTLINE, true)) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match('/focus-visible:-?outline-/', $line) !== 1) {
                    continue;
                }

                $violations[] = sprintf('%s:%d', $file->getRelativePathname(), $index + 1);
            }
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
