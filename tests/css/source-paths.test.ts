import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import type { ResolvedConfig } from 'vite';
import { describe, expect, it } from 'vitest';
import { missingSourcePaths, verifySourcePaths } from '../../vite/verify-source-paths';

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

    /**
     * Eine Direktive stillzulegen heißt, sie auszukommentieren. Tailwind überliest sie dann, der
     * Guard bräche den Build ab — und zwar mit der Begründung, es gebe den Pfad nicht.
     */
    it('liest eine auskommentierte Direktive nicht als Pfad', () => {
        expect(missingSourcePaths("/* @source '../viewsXX'; */", APP_CSS)).toEqual([]);
    });

    it('prüft eine Direktive hinter einem Kommentar weiter', () => {
        expect(missingSourcePaths("/* Hinweis */\n@source '../viewsXX';", APP_CSS)).toHaveLength(1);
    });
});

describe('verifySourcePaths', () => {
    /**
     * Vite lässt für jeden Hook auch die Objektform mit `handler` zu; dieses Plugin schreibt sie als
     * Funktionen, und nur so ruft der Test sie auf.
     */
    const plugin = verifySourcePaths('css/app.css');
    const configResolved = plugin.configResolved as (config: ResolvedConfig) => void;
    const buildStart = plugin.buildStart as (this: { error(message: string): never }) => void;

    /**
     * Den Kontext stellt im Build Rollup: Der Abbruch führt über `this.error()`, das von dort nie
     * zurückkehrt. Ohne ein `this` scheiterte der Hook stattdessen an einem TypeError.
     */
    const context = {
        error(message: string): never {
            throw new Error(message);
        },
    };

    /**
     * Setzt ein Build ein eigenes `root`, zeigt ein relativer Pfad woandershin als beim Start aus
     * dem Projektwurzelverzeichnis. Der Guard bräche dann mit ENOENT ab, statt zu prüfen.
     */
    it('liest die CSS-Datei relativ zu Vites root, nicht zum Arbeitsverzeichnis', () => {
        configResolved({ root: resolve('resources') } as ResolvedConfig);

        expect(() => buildStart.call(context)).not.toThrow();
    });

    /**
     * Der Abbruch ist der Zweck des Plugins: Ohne ihn liefe der Build durch und lieferte ein
     * Stylesheet, dem die Klassen des fehlenden Verzeichnisses wortlos fehlen.
     */
    it('bricht den Build ab, wenn ein @source-Pfad ins Leere zeigt', () => {
        const root = mkdtempSync(join(tmpdir(), 'verify-source-paths-'));
        mkdirSync(join(root, 'css'));
        writeFileSync(join(root, 'css/app.css'), "@source '../viewsXX';");

        configResolved({ root } as ResolvedConfig);

        try {
            expect(() => buildStart.call(context)).toThrow(/viewsXX$/);
        } finally {
            rmSync(root, { recursive: true });
        }
    });
});
