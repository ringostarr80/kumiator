import { describe, expect, it } from 'vitest';
import { baseLayerCss, hasCursorRule, isInCss, ruleSet } from './support/built-css';

/**
 * Eine Prüfung, die nichts findet, meldet dasselbe wie eine erfüllte Erwartung. Deshalb steht hier
 * jeweils auch der Fall, in dem sie anschlagen muss.
 */

describe('isInCss', () => {
    it('erkennt eine Klasse an ihrem Selektor', () => {
        expect(isInCss('.px-3{padding-inline:0.75rem}', 'px-3')).toBe(true);
    });

    it('meldet eine fehlende Klasse', () => {
        expect(isInCss('.px-3{padding-inline:0.75rem}', 'py-2')).toBe(false);
    });

    it('hält eine Klasse nicht für vorhanden, weil ein längerer Selektor mit ihr beginnt', () => {
        expect(isInCss('.px-30{padding-inline:7.5rem}', 'px-3')).toBe(false);
    });

    it('hält eine Klasse nicht für vorhanden, weil ein maskiertes Zeichen auf sie folgt', () => {
        expect(isInCss('.mt-0\\.5{margin-top:0.125rem}', 'mt-0')).toBe(false);
    });

    it('findet Klassen, deren Name in CSS maskiert wird', () => {
        expect(isInCss('.sm\\:w-1\\/2{width:50%}', 'sm:w-1/2')).toBe(true);
    });

    it('findet Klassen, deren führende Ziffer numerisch codiert wird', () => {
        expect(isInCss('.\\32 xl\\:flex{display:flex}', '2xl:flex')).toBe(true);
    });
});

describe('ruleSet', () => {
    const css = '.focus-ring:focus-visible{outline-width:2px}.focus-ring-inset:focus-visible{outline-width:1px}';

    it('sammelt jede Regel einer Klasse', () => {
        expect(ruleSet(`${css}.focus-ring:hover{outline-width:3px}`, 'focus-ring')).toHaveLength(2);
    });

    it('nimmt keine Regel einer Klasse mit längerem Namen mit', () => {
        expect(ruleSet(css, 'focus-ring')).toEqual(['.focus-ring:focus-visible{outline-width:2px}']);
    });

    it('nimmt keine Regel einer Klasse mit maskiertem Zeichen mit', () => {
        expect(ruleSet('.mt-0\\.5{margin-top:0.125rem}', 'mt-0')).toEqual([]);
    });

    it('findet Regeln von Klassen, deren Name in CSS maskiert wird', () => {
        expect(ruleSet('.sm\\:w-1\\/2{width:50%}', 'sm:w-1/2')).toEqual(['.sm\\:w-1\\/2{width:50%}']);
    });

    it('nimmt den Selektor mit, der links vom Klassennamen steht', () => {
        const wrapped = ':where(.space-y-1>:not(:last-child)){margin-block-start:0}';

        expect(ruleSet(wrapped, 'space-y-1')).toEqual([wrapped]);
    });

    it('endet an der Klammer der Regel, nicht an der eines verschachtelten Blocks', () => {
        const nested = '.focus-ring{outline-style:solid;@supports (outline-width:2px){outline-width:1px}outline-width:2px}';

        expect(ruleSet(nested, 'focus-ring')).toEqual([nested]);
    });
});

describe('hasCursorRule', () => {
    it('findet die Regel für nicht deaktivierte Buttons', () => {
        expect(hasCursorRule('a{color:red}button:not(:disabled){cursor:pointer}')).toBe(true);
    });

    it('meldet eine fehlende Regel', () => {
        expect(hasCursorRule('button{cursor:pointer}')).toBe(false);
    });
});

describe('baseLayerCss', () => {
    const css = '@layer base{a{color:red}}@layer utilities{.p-0{padding:0}}';

    it('liefert den Inhalt des base-Layers', () => {
        expect(baseLayerCss(css)).toBe('a{color:red}');
    });

    it('lässt eine Regel außerhalb der Layer-Kaskade weg', () => {
        expect(baseLayerCss(`b{color:blue}${css}`)).toBe('a{color:red}');
    });

    it('lässt einen Components-Block zwischen den Layern weg', () => {
        const withComponents = '@layer base{a{color:red}}@layer components{b{color:blue}}@layer utilities{.p-0{padding:0}}';

        expect(baseLayerCss(withComponents)).toBe('a{color:red}');
    });

    it('nimmt einen zweiten base-Block hinter den Utilities mit', () => {
        const split = `${css}@layer base{b{color:blue}}`;

        expect(baseLayerCss(split)).toBe('a{color:red}b{color:blue}');
    });

    /** Die Schreibweise hängt am Minifier; ein Build ohne ihn lieferte sonst einen leeren Layer. */
    it('findet den Block auch mit Leerzeichen vor der Klammer', () => {
        const spaced = '@layer base {\n  a {\n    color: red;\n  }\n}';

        expect(baseLayerCss(spaced)).toBe('\n  a {\n    color: red;\n  }\n');
    });

    it('endet an der Klammer des Blocks, nicht an der eines verschachtelten', () => {
        const nested = '@layer base{@media (width>=40rem){a{color:red}}}@layer utilities{.p-0{padding:0}}';

        expect(baseLayerCss(nested)).toBe('@media (width>=40rem){a{color:red}}');
    });
});
