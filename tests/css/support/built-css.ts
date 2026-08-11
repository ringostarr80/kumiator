import { readFileSync } from 'node:fs';

const MANIFEST_PATH = 'public/build/manifest.json';
const CSS_ENTRY = 'resources/css/app.css';

type Manifest = Record<string, { file: string } | undefined>;

export function readBuiltCss(): string {
    const manifest = JSON.parse(readFileSync(MANIFEST_PATH, 'utf8')) as Manifest;
    const entry = manifest[CSS_ENTRY];

    if (entry === undefined) {
        throw new Error(`${CSS_ENTRY} fehlt in ${MANIFEST_PATH}.`);
    }

    return readFileSync(`public/build/${entry.file}`, 'utf8');
}

/**
 * Tailwind maskiert im Selektor jedes Zeichen, das kein Wortzeichen ist; die zweite Ersetzung
 * entschärft dieselben Zeichen für die RegExp. Ein CSS-Bezeichner darf nicht mit einer Ziffer
 * beginnen, deshalb steht dort stattdessen ihr Zeichencode, abgeschlossen durch ein Leerzeichen.
 */
function selectorPattern(className: string): string {
    return className
        .replace(/[^\w-]/g, (character) => `\\${character}`)
        .replace(/[.*+?^${}()|[\]\\]/g, String.raw`\$&`)
        .replace(/^\d/, (digit) => String.raw`\\3${digit} `);
}

/**
 * Ohne diese Grenze meldet die Suche eine fehlende Klasse als vorhanden, sobald ein längerer Name
 * mit ihr beginnt: `.bg-white` schlüge in `.bg-white-alt` an, `.mt-0` in `.mt-0\.5`. Der Backslash
 * gehört deshalb dazu — er trennt in jedem maskierten Selektor. Ein unmaskiertes `:` bleibt außen
 * vor, sonst verlöre `ruleSet` die Pseudoklassen der eigenen Regeln.
 */
const CLASS_BOUNDARY = String.raw`(?![-\\\w])`;

export function isInCss(css: string, className: string): boolean {
    return new RegExp(String.raw`\.${selectorPattern(className)}${CLASS_BOUNDARY}`).test(css);
}

/**
 * Der Selektor beginnt hinter der letzten Grenze links vom Klassennamen. Ohne den Teil davor fiele
 * etwa das `:where(` weg, das Tailwind den `space-*`-Regeln voranstellt, und die zurückgegebene Regel
 * stünde mit unbalancierten Klammern da.
 */
function selectorStart(css: string, index: number): number {
    for (let position = index; position > 0; position--) {
        if (['{', '}', ';'].includes(css[position - 1])) {
            return position;
        }
    }

    return 0;
}

export function ruleSet(css: string, className: string): string[] {
    const pattern = new RegExp(String.raw`\.${selectorPattern(className)}${CLASS_BOUNDARY}[^{}]*\{`, 'g');

    return [...css.matchAll(pattern)].map((match) => {
        const end = blockEnd(css, match.index + match[0].length);

        return css.slice(selectorStart(css, match.index), end + 1);
    });
}

export function hasCursorRule(css: string): boolean {
    return /button:not\(:disabled\)[^{]*\{[^}]*cursor:\s*pointer/.test(css);
}

/** Ob zwischen `base` und der Klammer ein Leerzeichen steht, entscheidet der Minifier. */
const BASE_LAYER = String.raw`@layer\s+base\s*\{`;

/**
 * Ab `start` hinter der öffnenden Klammer, weil verschachtelte Blöcke wie `@media` sonst die erste
 * schließende Klammer zur Grenze machten.
 */
function blockEnd(css: string, start: number): number {
    let depth = 1;

    for (let index = start; index < css.length; index++) {
        if (css[index] === '{') {
            depth++;
        } else if (css[index] === '}') {
            depth--;

            if (depth === 0) {
                return index;
            }
        }
    }

    return css.length;
}

/**
 * Eine Basisregel schlägt jedes Utility, sobald sie außerhalb der Layer-Kaskade steht. Gesammelt wird
 * der Inhalt aller Blöcke, denn dass die Build-Kette den eigenen `@layer base` mit dem von Tailwind
 * zusammenfasst, ist nicht zugesichert.
 */
export function baseLayerCss(css: string): string {
    const pattern = new RegExp(BASE_LAYER, 'g');

    let content = '';
    let match = pattern.exec(css);

    while (match !== null) {
        const start = match.index + match[0].length;
        const end = blockEnd(css, start);

        content += css.slice(start, end);
        pattern.lastIndex = end;
        match = pattern.exec(css);
    }

    return content;
}
