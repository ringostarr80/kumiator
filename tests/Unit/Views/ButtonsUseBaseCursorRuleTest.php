<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\Support\BladeViews;
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

        foreach (BladeViews::lines() as $line) {
            if (!str_contains($line['content'], 'cursor-pointer')) {
                continue;
            }

            $violations[] = sprintf('%s:%d', $line['path'], $line['number']);
        }

        $this->assertSame(
            [],
            $violations,
            'Diese Stellen setzen die Zeigehand als Utility. Bei `button` und `[role="button"]` ist '
            . 'sie überflüssig und hebelt im deaktivierten Zustand die Basisregel aus.',
        );
    }
}
