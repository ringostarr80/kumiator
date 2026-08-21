<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Tests\Support\BladeViews;
use Tests\TestCase;

/**
 * `x-confirms-password` gibt seinen Dialog nicht selbst aus, weil der sonst mit den Bedingungen um die
 * Buttons wanderte und zeitweise in einem ausgeblendeten Teilbaum läge. Fehlt er in einer Ansicht,
 * bleibt der Knopf stumm: Die Bestätigung startet, nur sieht niemand ein Fenster — ohne Fehler und
 * ohne Meldung in der Konsole.
 *
 * Geprüft wird je Datei, weil `wire:model.live="confirmingPassword"` den Dialog an dieselbe
 * Livewire-Komponente bindet, die auch den Knopf trägt.
 */
final class ConfirmsPasswordCarriesItsDialogTest extends TestCase
{
    public function testEveryViewWithATriggerCarriesTheDialog(): void
    {
        $triggers = [];
        $dialogs = [];

        foreach (BladeViews::lines() as $line) {
            if (str_contains($line['content'], '<x-confirms-password')) {
                // Die erste Fundstelle genügt als Wegweiser, der Dialog fehlt ja für die ganze Datei.
                $triggers[$line['path']] ??= sprintf('%s:%d', $line['path'], $line['number']);
            }

            if (str_contains($line['content'], '<x-password-confirmation-modal')) {
                $dialogs[$line['path']] = true;
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff_key($triggers, $dialogs)),
            'Diesen Ansichten fehlt `<x-password-confirmation-modal>`. Ohne ihn nimmt der Knopf den '
            . 'Klick zwar an, es öffnet sich aber nichts.',
        );
    }
}
