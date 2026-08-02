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
 * fallen hier über die Sonderzeichen ihrer Syntax heraus.
 */
export function staticClasses(source: string): string[] {
    const classes = new Set<string>();

    for (const [, attribute] of source.matchAll(/class="([^"]*)"/g)) {
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
 * entschärft dieselben Zeichen für die RegExp.
 */
function selectorPattern(className: string): string {
    return className
        .replace(/[^\w-]/g, (character) => `\\${character}`)
        .replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Die Suche endet an der Klassengrenze, sonst würde `.bg-white` auch in `.bg-white-alt` anschlagen
 * und eine fehlende Klasse als vorhanden melden.
 */
export function isInCss(css: string, className: string): boolean {
    return new RegExp(`\\.${selectorPattern(className)}(?![\\w-])`).test(css);
}

/**
 * Die Suche endet an der Klassengrenze, sonst lieferte `focus-ring` auch die Regeln von
 * `focus-ring-inset` mit und eine fehlende Definition bliebe unbemerkt.
 */
export function ruleSet(css: string, className: string): string[] {
    const pattern = new RegExp(`\\.${selectorPattern(className)}(?![\\w-])[^{}]*\\{[^}]*\\}`, 'g');

    return [...css.matchAll(pattern)].map(([rule]) => rule);
}

export function findCursorRule(css: string): number | null {
    const match = /button:not\(:disabled\)[^{]*\{[^}]*cursor:\s*pointer/.exec(css);

    return match === null ? null : match.index;
}

/**
 * Eine Basisregel schlägt jedes Utility, sobald sie außerhalb der Layer-Kaskade steht: `cursor-default`
 * an der deaktivierten Pagination bliebe dann wirkungslos.
 */
export function isInBaseLayer(css: string, index: number): boolean {
    const baseLayer = css.indexOf('@layer base{');
    const utilitiesLayer = css.indexOf('@layer utilities{');

    return baseLayer !== -1 && utilitiesLayer !== -1 && index > baseLayer && index < utilitiesLayer;
}
