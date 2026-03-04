import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/video-player.tsx',
                'resources/js/photo-annotator.js',
                'resources/js/promo-player.tsx',
                'resources/js/comms-hub-player.tsx',
                'resources/js/pdf-viewer.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-echo': ['laravel-echo', 'pusher-js'],
                },
            },
        },
    },
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
