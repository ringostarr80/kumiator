import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { missingSourcePaths } from '../../vite/verify-source-paths';

const APP_CSS = 'resources/css/app.css';

describe('missingSourcePaths', () => {
    /**
     * Der eigentliche Regressionsschutz: Zeigt eine Direktive ins Leere, fehlen im Build sämtliche
     * Klassen des Verzeichnisses, ohne dass Tailwind es meldet.
     */
    it('findet jeden @source-Pfad der App', () => {
        expect(missingSourcePaths(readFileSync(APP_CSS, 'utf8'), APP_CSS)).toEqual([]);
    });

    it('meldet einen Pfad, den es nicht gibt', () => {
        const missing = missingSourcePaths("@source '../viewsXX';", APP_CSS);

        expect(missing).toHaveLength(1);
        expect(missing[0]).toMatch(/resources\/viewsXX$/);
    });

    it('löst den Pfad relativ zur CSS-Datei auf, nicht zum Arbeitsverzeichnis', () => {
        expect(missingSourcePaths("@source '../views';", APP_CSS)).toEqual([]);
    });

    it('erfasst auch die Schreibweise mit doppelten Anführungszeichen', () => {
        expect(missingSourcePaths('@source "../viewsXX";', APP_CSS)).toHaveLength(1);
    });

    it('meldet nichts, wenn gar keine Direktive vorkommt', () => {
        expect(missingSourcePaths("@import 'tailwindcss';", APP_CSS)).toEqual([]);
    });

    it('prüft bei einem Glob-Muster das Verzeichnis davor', () => {
        expect(missingSourcePaths("@source '../views/**/*.blade.php';", APP_CSS)).toEqual([]);
    });

    it('meldet ein Glob-Muster, dessen Verzeichnis fehlt', () => {
        const missing = missingSourcePaths("@source '../viewsXX/**/*.blade.php';", APP_CSS);

        expect(missing).toHaveLength(1);
        expect(missing[0]).toMatch(/resources\/viewsXX$/);
    });

    it('prüft auch den Ausschluss von @source not', () => {
        expect(missingSourcePaths("@source not '../viewsXX';", APP_CSS)).toHaveLength(1);
    });

    /** `inline(…)` zählt Utilities auf, statt auf Dateien zu zeigen — als Pfad gelesen wäre es immer tot. */
    it('liest @source inline nicht als Pfad', () => {
        expect(missingSourcePaths("@source inline('underline');", APP_CSS)).toEqual([]);
    });
});
