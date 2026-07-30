import { describe, expect, it } from 'vitest';
import { findCursorRule, isInBaseLayer, isInCss, ruleSet, staticClasses } from './support/built-css';

/**
 * Eine Prüfung, die nichts findet, meldet dasselbe wie eine erfüllte Erwartung. Deshalb steht hier
 * jeweils auch der Fall, in dem sie anschlagen muss.
 */

describe('staticClasses', () => {
    it('sammelt Klassen aus allen class-Attributen ohne Dubletten', () => {
        const source = '<a class="px-3 py-2"><span class="px-3">…</span></a>';

        expect(staticClasses(source)).toEqual(['px-3', 'py-2']);
    });

    it('überspringt Klassen, die erst zur Laufzeit entstehen', () => {
        const source = '<a class="px-3 {{ $active }} bg-{{ $color }}-500 {$legacy}">…</a>';

        expect(staticClasses(source)).toEqual(['px-3']);
    });
});

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

    it('findet Klassen, deren Name in CSS maskiert wird', () => {
        expect(isInCss('.sm\\:w-1\\/2{width:50%}', 'sm:w-1/2')).toBe(true);
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
});

describe('findCursorRule', () => {
    it('findet die Regel für nicht deaktivierte Buttons', () => {
        const css = 'a{color:red}button:not(:disabled){cursor:pointer}';

        expect(findCursorRule(css)).toBe(css.indexOf('button'));
    });

    it('meldet eine fehlende Regel', () => {
        expect(findCursorRule('button{cursor:pointer}')).toBeNull();
    });
});

describe('isInBaseLayer', () => {
    const css = '@layer base{a{color:red}}@layer utilities{.p-0{padding:0}}';

    it('erkennt eine Regel innerhalb des base-Layers', () => {
        expect(isInBaseLayer(css, css.indexOf('a{color:red}'))).toBe(true);
    });

    it('erkennt eine Regel außerhalb der Layer-Kaskade', () => {
        expect(isInBaseLayer(`a{color:red}${css}`, 0)).toBe(false);
    });
});
