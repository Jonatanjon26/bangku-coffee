import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

// Diisi compose.yaml saat Vite berjalan di container (http://vite.bangku.localhost lewat Traefik).
const asalDev = process.env.VITE_DEV_ORIGIN;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css', 'resources/css/pos.css', 'resources/css/kasir.css', 'resources/js/printer.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        ...(asalDev && {
            origin: asalDev,
            cors: true,
            hmr: { host: new URL(asalDev).hostname, clientPort: 80 },
        }),
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
