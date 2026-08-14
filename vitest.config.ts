import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/css/**/*.test.ts'],
        globalSetup: ['tests/css/global-setup.ts'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcovonly'],
            include: ['vite/**', 'resources/js/**'],
            /**
             * Nur das Plugin steht unter Test; resources/js/ läuft ungeprüft mit, damit die Lücke
             * im Bericht sichtbar bleibt, statt aus der Messung zu verschwinden.
             */
            thresholds: {
                'vite/**': { statements: 100, branches: 100, functions: 100, lines: 100 },
            },
        },
    },
});
