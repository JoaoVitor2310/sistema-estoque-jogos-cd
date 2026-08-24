import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // The Vue plugin will re-write asset URLs, when referenced
                    // in Single File Components, to point to the Laravel web
                    // server. Setting this to `null` allows the Laravel plugin
                    // to instead re-write asset URLs to point to the Vite
                    // server instead.
                    base: null,

                    // The Vue plugin will parse absolute URLs and treat them
                    // as absolute paths to files on disk. Setting this to
                    // `false` will leave absolute URLs un-touched so they can
                    // reference assets in the public directory as expected.
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        host: '0.0.0.0', // Permite que o Vite seja acessível de fora do container
        port: 5173,      // Porta que o Vite usará
        hmr: {
            host: 'localhost', // Ajuste para o host que você está usando para acessar o Vite
            port: 5173,
            clientPort: 5173,
        },
        watch: {
            usePolling: true,
            // Polling faz stat() na árvore inteira a cada intervalo, e o bind
            // mount do container traz junto `vendor/` — milhares de arquivos do
            // Composer que nunca mudam em runtime. O ignore default do chokidar
            // cobre só `node_modules` e `.git`; sem a lista abaixo o watcher
            // sozinho ocupa um core inteiro (incidente de 2026-08-24, quando um
            // container subiu em modo dev na VPS e a máquina ficou inacessível).
            // `storage/` entra pelo motivo oposto: muda o tempo todo (logs,
            // cache, sessões) e dispara reload sem que nada de fonte tenha
            // mudado.
            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/backups/**',
                '**/docker/**',
                '**/node_modules/**',
                '**/.git/**',
            ],
        },
    },
});
