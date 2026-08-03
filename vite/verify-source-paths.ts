import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Plugin } from 'vite';

/**
 * Tailwind erlaubt beide Anführungszeichen; nur eines zu erfassen ließe die andere Form ungeprüft.
 * `not` schließt Pfade aus und gehört mitgeprüft, `inline(…)` zählt dagegen Utilities auf und bleibt
 * mangels Anführungszeichen direkt hinter `@source` von selbst draußen.
 */
const SOURCE_DIRECTIVE = /@source\s+(?:not\s+)?(['"])([^'"]+)\1/g;

/** Ab dem ersten Platzhalter beschreibt der Wert keinen Eintrag mehr, den es geben könnte. */
const WILDCARD = /[*?[{]/;

/**
 * Bei einem Glob-Muster bleibt nur das Verzeichnis davor prüfbar. Das genügt für den Fehler, um den es
 * geht — ein Paket ist umbenannt oder verschwunden —, und meldet nichts, solange ein gültiges Muster
 * bloß noch keine Datei trifft.
 */
function checkablePath(path: string): string {
    const wildcard = path.search(WILDCARD);

    if (wildcard === -1) {
        return path;
    }

    const separator = path.lastIndexOf('/', wildcard);

    return separator === -1 ? '.' : path.slice(0, separator);
}

/**
 * Die Pfade stehen relativ zur CSS-Datei, nicht zum Arbeitsverzeichnis.
 */
export function missingSourcePaths(css: string, cssPath: string): string[] {
    const directory = dirname(cssPath);

    return [...css.matchAll(SOURCE_DIRECTIVE)]
        .map(([, , path]) => resolve(directory, checkablePath(path)))
        .filter((path) => !existsSync(path));
}

/**
 * Einen @source-Pfad, den es nicht gibt, übergeht Tailwind wortlos: Der Build endet erfolgreich und
 * lässt jede Klasse des Verzeichnisses weg — die Pagination des Activity-Logs rendert dann ungestylt.
 * Erst dieser Abbruch macht daraus einen Fehler, und zwar überall dort, wo gebaut wird.
 */
export function verifySourcePaths(cssPath: string): Plugin {
    return {
        name: 'verify-source-paths',
        buildStart() {
            const missing = missingSourcePaths(readFileSync(cssPath, 'utf8'), cssPath);

            if (missing.length > 0) {
                this.error(`Diese @source-Pfade aus ${cssPath} gibt es nicht:\n${missing.join('\n')}`);
            }
        },
    };
}
