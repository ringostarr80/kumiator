<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class SwitcherOptionTest extends TestCase
{
    /**
     * Der Theme-Umschalter kennt keine Adresse: Seine Einträge schalten nur Alpine-Zustand um und
     * müssen deshalb Buttons bleiben.
     */
    public function testRendersAButtonWithoutHref(): void
    {
        $html = Blade::render('<x-switcher-option>System</x-switcher-option>');

        $this->assertStringContainsString('<button ', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('focus-ring-inset', $html);
    }

    public function testRendersALinkWithHref(): void
    {
        $html = Blade::render('<x-switcher-option href="/locale/de">Deutsch</x-switcher-option>');

        $this->assertStringContainsString('<a href="/locale/de"', $html);
        $this->assertStringContainsString('focus-ring-inset', $html);
    }

    /**
     * Aktion und Markierung der Einträge stehen in Alpine-Attributen; verlöre die Komponente sie,
     * ließe sich das Menü nicht mehr bedienen.
     */
    public function testKeepsAlpineAttributes(): void
    {
        $tag = '<x-switcher-option @click="setTheme(\'dark\')" ::class="{ \'font-semibold\': true }">';

        $html = Blade::render("{$tag}Dark</x-switcher-option>");

        $this->assertStringContainsString('@click="setTheme(\'dark\')"', $html);
        $this->assertStringContainsString(':class="{ \'font-semibold\': true }"', $html);
    }

    /** Die Rezeptur der Komponente darf die Klassen der Aufrufstelle nicht verdrängen. */
    public function testMergesClassesFromTheCallSite(): void
    {
        $html = Blade::render('<x-switcher-option class="w-full">System</x-switcher-option>');

        $this->assertMatchesRegularExpression('/class="[^"]*focus-ring-inset[^"]*w-full/', $html);
    }
}
