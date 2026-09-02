// SPDX-License-Identifier: Apache-2.0

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/reporter.css',
                'resources/js/reporter.js',
                'resources/css/dashboard.css',
                'resources/js/dashboard.js',
                // The API reference. A real module rather than an inline block:
                // the public pages run `script-src 'self'`, and one inline
                // <script> would put 'unsafe-inline' back into the policy.
                'resources/js/docs.js',
            ],
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
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
