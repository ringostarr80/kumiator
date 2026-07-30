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

describe('Fokusring', () => {
    /**
     * Alle Bedienelemente teilen sich dieselben zwei Utilities: Bleiben sie im Build aus, verliert die
     * gesamte App ihre Tastaturanzeige, ohne dass eine Ansicht sich geändert hätte.
     */
    it.each(['focus-ring', 'focus-ring-inset'])('zeichnet %s in beiden Modi', (utility) => {
        const rules = ruleSet(css, utility);

        expect(rules.filter((rule) => /outline-width:\s*2px/.test(rule) && rule.includes('--color-indigo-600')))
            .toHaveLength(1);
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
