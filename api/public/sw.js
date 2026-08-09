// SPDX-License-Identifier: Apache-2.0

/**
 * Qeema reporter service worker.
 *
 * Hand-written rather than generated: the caching rules here are few and
 * specific, and a reporter on a 2G connection benefits more from understanding
 * exactly what is cached than from a general-purpose runtime.
 *
 * Two rules matter:
 *   - the app shell and bootstrap snapshot are cache-first, so the app opens
 *     and is usable with no connection at all;
 *   - submissions are NEVER cached or replayed by the worker. The IndexedDB
 *     outbox owns that, because only it can guarantee the idempotency key
 *     survives a retry.
 */

const VERSION = 'v1';
const SHELL_CACHE = `qeema-shell-${VERSION}`;
const DATA_CACHE = `qeema-data-${VERSION}`;

const SHELL_ASSETS = ['/report', '/offline', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            // Individually, so one missing asset cannot fail the whole install
            // and leave the app with no worker at all.
            .then((cache) => Promise.allSettled(SHELL_ASSETS.map((url) => cache.add(url))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key.startsWith('qeema-') && !key.endsWith(VERSION))
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        // Submissions go straight to the network. If it fails, the outbox in
        // IndexedDB already holds the item — the worker must not attempt its
        // own replay, or the same price could be sent twice under two
        // different idempotency keys.
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // The offline bootstrap snapshot: serve from cache immediately, refresh in
    // the background. A reporter opening the app in a dead zone still gets
    // their locations and item list.
    if (url.pathname.startsWith('/api/v1/bootstrap/')) {
        event.respondWith(staleWhileRevalidate(request, DATA_CACHE));
        return;
    }

    // Content-hashed build assets never change under a given URL.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, SHELL_CACHE));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstWithOfflineFallback(request));
    }
});

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        const cache = await caches.open(cacheName);
        cache.put(request, response.clone());
    }

    return response;
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            if (response.ok) {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => null);

    return cached ?? (await network) ?? new Response('{}', {
        status: 503,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function networkFirstWithOfflineFallback(request) {
    try {
        const response = await fetch(request);

        if (response.ok) {
            const cache = await caches.open(SHELL_CACHE);
            cache.put(request, response.clone());
        }

        return response;
    } catch {
        const cached = await caches.match(request);

        // Falling back to /report rather than a generic offline page: the app
        // works offline, so landing on "you are offline" would be misleading.
        return cached ?? (await caches.match('/report')) ?? (await caches.match('/offline'));
    }
}

/**
 * Background Sync, where the browser supports it.
 *
 * The worker does not itself replay submissions — it wakes a client, which owns
 * the outbox and its idempotency keys. If no client is running, the flush
 * happens next time the app is opened.
 */
self.addEventListener('sync', (event) => {
    if (event.tag !== 'qeema-flush-outbox') {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ includeUncontrolled: true, type: 'window' }).then((clients) => {
            clients.forEach((client) => client.postMessage({ type: 'qeema:flush-outbox' }));
        }),
    );
});
