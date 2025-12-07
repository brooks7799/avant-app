import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { defineConfig } from 'vite';

export default defineConfig({
    // Prevent infinite reload loops
    clearScreen: false,
    logLevel: 'info',
    server: {
        port: parseInt(process.env.VITE_PORT || '5173'),
        host: '127.0.0.1',
        warmup: {
            clientFiles: ['./resources/js/app.ts', './resources/css/app.css'],
        },
        hmr: {
            host: '127.0.0.1',
            overlay: false,
            // Prevent infinite reload on errors
            protocol: 'ws',
            timeout: 5000,
        },
        cors: true,
        watch: {
            // Disable polling to reduce connection spam
            usePolling: false,
            // Ignore directories that shouldn't trigger reloads
            ignored: [
                '**/node_modules/**',
                '**/.git/**',
                '**/storage/framework/**',
                '**/storage/logs/**',
                '**/storage/app/**',
                '**/bootstrap/cache/**',
                '**/vendor/**'
            ],
        },
    },
    css: {
        devSourcemap: false,
    },
    build: {
        sourcemap: false,
        rollupOptions: {
            output: {
                manualChunks: {
                    vendor: ['vue', '@inertiajs/vue3'],
                },
            },
        },
    },
    optimizeDeps: {
        include: [
            'vue',
            '@inertiajs/vue3',
            'lucide-vue-next',
            'reka-ui',
            'class-variance-authority',
            'clsx',
            'tailwind-merge',
        ],
        exclude: ['@tailwindcss/vite'],
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: {
                paths: [
                    'resources/views/**',
                    'resources/js/**',
                    'routes/**',
                ],
                // Add delay to prevent rapid refreshes
                delay: 300,
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
});
