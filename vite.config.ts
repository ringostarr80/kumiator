import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { verifySourcePaths } from './vite/verify-source-paths';

export default defineConfig({
    plugins: [
        verifySourcePaths('resources/css/app.css'),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
