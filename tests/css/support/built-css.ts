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
 * Klassen aus Blade-Ausdrücken stehen erst zur Laufzeit fest und sind deshalb nicht prüfbar; sie
 * fallen hier über die Sonderzeichen ihrer Syntax heraus. Ein gebundenes Attribut (`:class`) trägt
 * statt einer Klassenliste einen Alpine-Ausdruck, dessen Bruchstücke sonst als fehlende Klassen
 * gemeldet würden.
 */
export function staticClasses(source: string): string[] {
    const classes = new Set<string>();

    for (const [, , attribute] of source.matchAll(/(?<![:\w])class=(["'])([\s\S]*?)\1/g)) {
        for (const token of attribute.split(/\s+/)) {
            if (token !== '' && !/[{}$]/.test(token)) {
                classes.add(token);
            }
        }
    }

    return [...classes].sort();
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

export function ruleSet(css: string, className: string): string[] {
    const pattern = new RegExp(String.raw`\.${selectorPattern(className)}${CLASS_BOUNDARY}[^{}]*\{[^}]*\}`, 'g');

    return [...css.matchAll(pattern)].map(([rule]) => rule);
}

export function hasCursorRule(css: string): boolean {
    return /button:not\(:disabled\)[^{]*\{[^}]*cursor:\s*pointer/.test(css);
}

const BASE_LAYER = '@layer base{';

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
    let content = '';
    let index = css.indexOf(BASE_LAYER);

    while (index !== -1) {
        const start = index + BASE_LAYER.length;
        const end = blockEnd(css, start);

        content += css.slice(start, end);
        index = css.indexOf(BASE_LAYER, end);
    }

    return content;
}
