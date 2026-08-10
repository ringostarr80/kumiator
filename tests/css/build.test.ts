/**
 * Geprüft wird das gebaute Stylesheet: Ob eine Klasse den Build erreicht und was Tailwind aus
 * `@utility` und `@layer base` macht, steht in keiner Quelldatei.
 */

import { describe, expect, it } from 'vitest';
import { baseLayerCss, hasCursorRule, isInCss, readBuiltCss, ruleSet } from './support/built-css';

const css = readBuiltCss();

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
        expect(css).toMatch(/\.dark\s*\{[^}]*--color-focus:\s*var\(--color-indigo-300\)/);
    });
});

describe('Quellsuche', () => {
    /**
     * Die automatische Suche liest jede nicht ignorierte Datei als Klassenquelle, auch solche, die nie
     * ausgeliefert wird: Ein Negativbeispiel aus einem Test und eine Supervisor-Zeile aus der
     * Betriebsdokumentation standen als Utility im Stylesheet.
     */
    it.each<[string, string]>([
        ['Testdateien', 'px-30'],
        ['der Betriebsdokumentation', '[program:kumiator-worker]'],
    ])('lässt Klassen aus %s draußen', (_herkunft, className) => {
        expect(isInCss(css, className)).toBe(false);
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
