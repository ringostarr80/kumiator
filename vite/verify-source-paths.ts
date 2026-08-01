import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import type { Plugin } from 'vite';

/** Tailwind erlaubt beide Anführungszeichen; nur eines zu erfassen ließe die andere Form ungeprüft. */
const SOURCE_DIRECTIVE = /@source\s+(['"])([^'"]+)\1/g;

/**
 * Die Pfade stehen relativ zur CSS-Datei, nicht zum Arbeitsverzeichnis.
 */
export function missingSourcePaths(css: string, cssPath: string): string[] {
    const directory = dirname(cssPath);

    return [...css.matchAll(SOURCE_DIRECTIVE)]
        .map(([, , path]) => resolve(directory, path))
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
