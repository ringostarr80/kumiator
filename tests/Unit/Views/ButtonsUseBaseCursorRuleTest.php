<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Die Zeigehand für Bedienelemente kommt aus der Basisregel in `resources/css/app.css`, die
 * deaktivierte davon ausnimmt. Eine `cursor-pointer`-Klasse liegt dagegen im utilities-Layer und
 * schlägt die Basisregel unabhängig von der Spezifität — ein per `wire:loading` deaktivierter Button
 * behielte damit die Zeigehand und sähe weiter anklickbar aus.
 */
final class ButtonsUseBaseCursorRuleTest extends TestCase
{
    public function testViewsLeaveTheCursorToTheBaseRule(): void
    {
        $violations = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (!str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'cursor-pointer')) {
                    continue;
                }

                $violations[] = sprintf('%s:%d', $file->getRelativePathname(), $index + 1);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Diese Stellen setzen die Zeigehand als Utility. Bei `button` und `[role="button"]` ist '
            . 'sie überflüssig und hebelt im deaktivierten Zustand die Basisregel aus.',
        );
    }
}
