/**
 * Geprüft wird das gebaute Stylesheet: Ob eine Klasse den Build erreicht und was Tailwind aus
 * `@utility` und `@layer base` macht, steht in keiner Quelldatei.
 */

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { baseLayerCss, hasCursorRule, isInCss, readBuiltCss, ruleSet, staticClasses } from './support/built-css';

const LIVEWIRE_VIEWS = 'vendor/livewire/livewire/src/Features/SupportPagination/views/';
const LARAVEL_VIEWS = 'vendor/laravel/framework/src/Illuminate/Pagination/resources/views/';

const css = readBuiltCss();

describe('Pagination', () => {
    /**
     * Die Ansichten liegen unterhalb von /vendor, das die Quellsuche wegen .gitignore überspringt.
     * Fehlt die passende @source-Direktive, entfällt jede ihrer Klassen ersatzlos aus dem Build.
     * Livewire rendert die Ansicht des Activity-Logs, Laravel jeden Paginator außerhalb einer
     * Komponente; die einfachen Fassungen greifen bei `simplePaginate()` und `cursorPaginate()`.
     */
    it.each<[string, string]>([
        ['Livewire', `${LIVEWIRE_VIEWS}tailwind.blade.php`],
        ['einfachen Livewire', `${LIVEWIRE_VIEWS}simple-tailwind.blade.php`],
        ['Laravel', `${LARAVEL_VIEWS}tailwind.blade.php`],
        ['einfachen Laravel', `${LARAVEL_VIEWS}simple-tailwind.blade.php`],
    ])('bringt jede statische Klasse der %s-Ansicht in den Build', (_theme, view) => {
        const classes = staticClasses(readFileSync(view, 'utf8'));

        expect(classes.length).toBeGreaterThan(0);
        expect(classes.filter((className) => !isInCss(css, className))).toEqual([]);
    });
});

/**
 * Geprüft wird allein das Vorzeichen: Ob der Ring innen oder außen liegt, ist die Designentscheidung —
 * ob Tailwind sie als calc(2px * -1) oder als -2px ausdrückt, ist seine Sache.
 */
const drawsInward = (rule: string): boolean => /outline-offset:\s*(-\d|calc\([^)]*-1\s*\))/.test(rule);

describe('Fokusring', () => {
    /**
     * Alle Bedienelemente teilen sich dieselben zwei Utilities: Bleiben sie im Build aus, verliert die
     * gesamte App ihre Tastaturanzeige, ohne dass eine Ansicht sich geändert hätte.
     */
    it.each<[string, boolean]>([
        ['focus-ring', false],
        ['focus-ring-inset', true],
    ])('zeichnet %s in der Fokusfarbe', (utility, inward) => {
        const drawn = ruleSet(css, utility)
            .filter((rule) => /outline-width:\s*2px/.test(rule) && rule.includes('var(--color-focus)'));

        expect(drawn).toHaveLength(1);
        expect(drawsInward(drawn[0])).toBe(inward);
    });

    /**
     * Die Utilities tragen keine dark:-Variante mehr; bliebe der Umschaltpunkt aus, zeichnete der
     * Dunkelmodus seinen Ring unbemerkt in der hellen Farbe.
     */
    it('schaltet die Fokusfarbe im Dunkelmodus um', () => {
        expect(css).toMatch(/--color-focus:\s*var\(--color-indigo-600\)/);
        expect(css).toMatch(/\.dark\{[^}]*--color-focus:\s*var\(--color-indigo-300\)/);
    });
});

describe('Buttons', () => {
    it('zeigt auf anklickbaren Elementen die Zeigehand', () => {
        expect(hasCursorRule(css)).toBe(true);
    });

    it('hält die Zeigehand im base-Layer, damit Utilities sie überschreiben können', () => {
        expect(hasCursorRule(baseLayerCss(css))).toBe(true);
    });
});
