// SPDX-License-Identifier: Apache-2.0
import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end configuration.
 *
 * Runs against the composed stack rather than a dev server, because the thing
 * being tested — a service worker plus an IndexedDB outbox — behaves
 * differently under `vite dev` than it does in a built deployment, and the
 * built deployment is what a reviewer will see.
 */
export default defineConfig({
    testDir: './tests',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    // Offline tests manipulate a shared browser context's network state, so
    // running them concurrently would make them interfere with each other.
    workers: 1,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

    timeout: 60_000,
    expect: { timeout: 15_000 },

    use: {
        baseURL: process.env.QEEMA_BASE_URL ?? 'http://localhost:8080',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        // Service workers are the whole point of these tests, so they are
        // explicitly left enabled.
        serviceWorkers: 'allow',
    },

    projects: [
        {
            name: 'mobile-chromium',
            // The target device is a low-end Android phone, not a desktop.
            use: { ...devices['Pixel 5'] },
        },
    ],
});
