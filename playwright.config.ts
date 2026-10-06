import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { parseEnv } from 'node:util';
import { defineConfig } from '@playwright/test';

const envFile = join(import.meta.dirname, '.env.e2e');

// Ohne die Datei lädt Laravel bei APP_ENV=e2e stillschweigend die .env, und migrate:fresh leerte
// die Entwicklungsdatenbank. Die Prüfung steht hier, weil Playwright den Webserver vor jedem
// globalSetup startet.
if (!existsSync(envFile)) {
    throw new Error('.env.e2e is missing, refusing to start against the database configured in .env');
}

// Laravel lässt schon gesetzte Umgebungsvariablen stehen. Erst als Prozessumgebung übergeben,
// gewinnt die Datei auch gegen eine Shell oder einen Container, der die .env geladen hat. Was
// sie nicht nennt, kommt weiter von dort.
const e2eEnv = parseEnv(readFileSync(envFile, 'utf8'));

const baseURL = 'http://127.0.0.1:8123';

export default defineConfig({
    testDir: 'tests/e2e',
    // Playwright leert Ausgabe- und Berichtsverzeichnis bei jedem Lauf, deshalb liegen beide
    // getrennt unter build/
    outputDir: 'build/playwright/results',
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI
        ? [['github'], ['html', { open: 'never', outputFolder: 'build/playwright/report' }]]
        : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
    },
    webServer: {
        // Jeder Lauf beginnt mit einer frischen Datenbank, damit kein Test vom Zustand eines früheren abhängt
        command: 'php artisan migrate:fresh --seed --seeder=E2eSeeder && php artisan serve --port=8123',
        url: `${baseURL}/up`,
        // Damit laden Migration und Server .env.e2e statt .env
        env: {
            ...e2eEnv,
            APP_ENV: 'e2e',
            // Sonst zeigte app.url auf http://localhost oder die APP_URL der Umgebung statt auf
            // diesen Server
            APP_URL: baseURL,
            // Ein Config-Cache aus der Entwicklung ersetzt jede env-Datei, und migrate:fresh träfe
            // deren Datenbank. Unter diesem Pfad legt niemand einen an.
            APP_CONFIG_CACHE: 'bootstrap/cache/config.e2e.php',
        },
        // Ein schon laufender Server auf dem Port könnte mit der .env gestartet sein
        reuseExistingServer: false,
    },
});
