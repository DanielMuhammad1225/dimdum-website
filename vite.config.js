import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

/*
 * Font DIMDUM di-self-host dari resources/fonts (WOFF2, subset latin).
 *
 * File berasal dari Bunny Fonts (Fredoka & Poppins, SIL Open Font License)
 * dan disimpan di dalam project supaya:
 *  - hanya format WOFF2 yang dimuat (provider remote menghasilkan @font-face
 *    WOFF2 *dan* WOFF terpisah, sehingga browser mengunduh keduanya),
 *  - build tidak lagi memerlukan koneksi internet.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Headline & harga.
                local('Fredoka', {
                    alias: 'display',
                    variable: '--font-fredoka',
                    variants: [
                        { src: 'resources/fonts/fredoka-600.woff2', weight: 600, style: 'normal' },
                        { src: 'resources/fonts/fredoka-700.woff2', weight: 700, style: 'normal' },
                    ],
                    optimizedFallbacks: false,
                    fallbacks: ['ui-rounded', 'system-ui', 'sans-serif'],
                    preload: [{ weight: 700 }],
                }),
                // Subheadline, navigasi & body.
                local('Poppins', {
                    alias: 'body',
                    variable: '--font-poppins',
                    variants: [
                        { src: 'resources/fonts/poppins-400.woff2', weight: 400, style: 'normal' },
                        { src: 'resources/fonts/poppins-500.woff2', weight: 500, style: 'normal' },
                        { src: 'resources/fonts/poppins-600.woff2', weight: 600, style: 'normal' },
                        { src: 'resources/fonts/poppins-700.woff2', weight: 700, style: 'normal' },
                    ],
                    optimizedFallbacks: false,
                    fallbacks: ['system-ui', 'sans-serif'],
                    preload: [{ weight: 400 }, { weight: 600 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
