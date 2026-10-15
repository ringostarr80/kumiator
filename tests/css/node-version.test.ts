import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const DOCKERFILE = 'docker/php/Dockerfile';
const NVMRC = '.nvmrc';
const PACKAGE_JSON = 'package.json';

function majorOf(version: string): string {
    const major = /\d+/.exec(version);
    if (major === null) {
        throw new Error(`In "${version}" steht keine Versionsnummer`);
    }

    return major[0];
}

describe('Node', () => {
    /**
     * tsc prüft gegen die Typen der Major-Version aus .nvmrc. Läuft eine andere Engine, bestehen
     * APIs die Typprüfung, die es zur Laufzeit nicht gibt.
     */
    it('läuft in der Major-Version aus .nvmrc', () => {
        expect(majorOf(process.versions.node)).toBe(majorOf(readFileSync(NVMRC, 'utf8')));
    });
});

describe('@types/node', () => {
    /**
     * dependabot.yml hält Major-Updates des Pakets zurück, damit die Typen der Engine nicht vorauseilen.
     * Die Gegenrichtung bleibt dabei offen: Nach einem .nvmrc-Bump beschreiben die alten Typen weiter
     * APIs, die die neue Engine nicht mehr hat, und tsc winkt sie bis zur Laufzeit durch.
     */
    it('folgt der Node-Major-Version aus .nvmrc', () => {
        const pkg = JSON.parse(readFileSync(PACKAGE_JSON, 'utf8')) as { devDependencies: Record<string, string> };

        expect(majorOf(pkg.devDependencies['@types/node'])).toBe(majorOf(readFileSync(NVMRC, 'utf8')));
    });
});

describe('engines', () => {
    /**
     * composer deploy installiert mit --engine-strict und verlangt damit die Engine aus diesem Feld. Bliebe es
     * nach einem .nvmrc-Bump stehen, liefe der Produktionsserver weiter auf der alten Version.
     */
    it('verlangt Node in der Major-Version aus .nvmrc', () => {
        const pkg = JSON.parse(readFileSync(PACKAGE_JSON, 'utf8')) as { engines: { node: string } };

        expect(majorOf(pkg.engines.node)).toBe(majorOf(readFileSync(NVMRC, 'utf8')));
    });
});

describe('Dockerfile', () => {
    /**
     * Den Tag des Node-Images pflegt Dependabot nicht. Nach einem .nvmrc-Bump liefe der Container sonst
     * unbemerkt weiter auf der alten Engine.
     */
    it('übernimmt Node in der Major-Version aus .nvmrc', () => {
        const major = majorOf(readFileSync(NVMRC, 'utf8'));

        expect(readFileSync(DOCKERFILE, 'utf8')).toMatch(new RegExp(String.raw`^FROM node:${major}\b`, 'm'));
    });
});
