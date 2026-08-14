import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const NVMRC = '.nvmrc';
const PACKAGE_JSON = 'package.json';

function majorOf(version: string): string {
    const major = /\d+/.exec(version);
    if (major === null) {
        throw new Error(`In "${version}" steht keine Versionsnummer`);
    }

    return major[0];
}

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
