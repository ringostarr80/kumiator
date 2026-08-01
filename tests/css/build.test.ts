import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { findCursorRule, isInBaseLayer, isInCss, readBuiltCss, ruleSet, staticClasses } from './support/built-css';

/**
 * Beide Eigenschaften entstehen erst beim Tailwind-Build und sind im Blade-Quelltext nicht zu sehen.
 */

const PAGINATION_VIEW = 'vendor/livewire/livewire/src/Features/SupportPagination/views/tailwind.blade.php';

const css = readBuiltCss();

describe('Pagination', () => {
    /**
     * Die Ansicht liegt unterhalb von /vendor, das die Quellsuche wegen .gitignore überspringt. Fehlt
     * die passende @source-Direktive, entfällt jede ihrer Klassen ersatzlos aus dem Build.
     */
    it('bringt jede statische Klasse der Livewire-Ansicht in den Build', () => {
        const classes = staticClasses(readFileSync(PAGINATION_VIEW, 'utf8'));

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
    ])('zeichnet %s in beiden Modi', (utility, inward) => {
        const rules = ruleSet(css, utility);
        const light = rules.filter((rule) => /outline-width:\s*2px/.test(rule) && rule.includes('--color-indigo-600'));

        expect(light).toHaveLength(1);
        expect(drawsInward(light[0])).toBe(inward);
        expect(rules.filter((rule) => rule.includes('--color-indigo-300') && rule.includes('.dark')))
            .toHaveLength(1);
    });
});

describe('Buttons', () => {
    it('zeigt auf anklickbaren Elementen die Zeigehand', () => {
        expect(findCursorRule(css)).not.toBeNull();
    });

    it('hält die Zeigehand im base-Layer, damit Utilities sie überschreiben können', () => {
        const index = findCursorRule(css);

        expect(index).not.toBeNull();
        expect(isInBaseLayer(css, index ?? 0)).toBe(true);
    });
});
