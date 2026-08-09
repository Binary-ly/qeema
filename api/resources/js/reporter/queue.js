// SPDX-License-Identifier: Apache-2.0

/**
 * Offline submission queue.
 *
 * Livewire is server-driven and cannot function with no connection, so the
 * submission path is deliberately plain JS against a JSON endpoint. Everything
 * a reporter enters lands in IndexedDB first and is only ever removed once the
 * server has acknowledged it. That ordering is the whole point: a phone that
 * loses signal mid-submission must lose nothing.
 *
 * Every queued item carries a client-generated UUID as its idempotency key.
 * The server enforces uniqueness on it, so replaying a queue after a dropped
 * connection cannot double-count a price — which would be a silent distortion
 * of a published figure rather than a visible error.
 */

const DB_NAME = 'qeema-reporter';
const DB_VERSION = 1;
const STORE = 'outbox';

/** Give up on an item after this many failed attempts and surface it. */
const MAX_ATTEMPTS = 8;

export const STATUS = {
    PENDING: 'pending',
    SYNCING: 'syncing',
    SYNCED: 'synced',
    FAILED: 'failed',
};

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;

            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'key' });
                store.createIndex('status', 'status', { unique: false });
                store.createIndex('createdAt', 'createdAt', { unique: false });
            }
        };

        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transaction(db, mode, fn) {
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const store = tx.objectStore(STORE);
        let result;

        try {
            result = fn(store);
        } catch (error) {
            reject(error);
            return;
        }

        tx.oncomplete = () => resolve(result);
        tx.onerror = () => reject(tx.error);
        tx.onabort = () => reject(tx.error);
    });
}

function promisify(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

/**
 * A stable per-device identity.
 *
 * Not authentication and not a secret — it exists so a reputation can accrue
 * without demanding a signup that would suppress participation.
 */
export function reporterRef() {
    let ref = localStorage.getItem('qeema.reporter_ref');

    if (!ref) {
        ref = crypto.randomUUID();
        localStorage.setItem('qeema.reporter_ref', ref);
    }

    return ref;
}

export async function enqueue(submission) {
    const db = await openDatabase();

    const item = {
        // Generated here, once, and never regenerated on retry. Regenerating it
        // would defeat the server-side uniqueness constraint entirely.
        key: crypto.randomUUID(),
        payload: {
            ...submission,
            reporter_ref: reporterRef(),
            client_idempotency_key: crypto.randomUUID(),
        },
        status: STATUS.PENDING,
        attempts: 0,
        lastError: null,
        createdAt: Date.now(),
    };

    await transaction(db, 'readwrite', (store) => store.add(item));

    return item;
}

export async function all() {
    const db = await openDatabase();

    return transaction(db, 'readonly', (store) => promisify(store.getAll())).then((r) => r ?? []);
}

async function pending() {
    const items = await all();

    return items.filter((i) => i.status === STATUS.PENDING || i.status === STATUS.SYNCING);
}

export async function counts() {
    const items = await all();

    return items.reduce(
        (acc, item) => {
            acc[item.status] = (acc[item.status] ?? 0) + 1;
            return acc;
        },
        { pending: 0, syncing: 0, synced: 0, failed: 0 },
    );
}

async function update(item) {
    const db = await openDatabase();

    return transaction(db, 'readwrite', (store) => store.put(item));
}

export async function remove(key) {
    const db = await openDatabase();

    return transaction(db, 'readwrite', (store) => store.delete(key));
}

/**
 * Drop everything already confirmed by the server.
 *
 * Synced items are kept briefly so the UI can show "sent", then cleared to
 * keep IndexedDB from growing without bound on a heavily-used device.
 */
export async function pruneSynced(olderThanMs = 60_000) {
    const items = await all();
    const cutoff = Date.now() - olderThanMs;

    await Promise.all(
        items
            .filter((i) => i.status === STATUS.SYNCED && i.syncedAt && i.syncedAt < cutoff)
            .map((i) => remove(i.key)),
    );
}

/**
 * Attempt to send everything queued.
 *
 * Returns a summary rather than throwing: a partial flush is the normal case on
 * an intermittent connection, and the caller wants to know what got through.
 */
export async function flush(endpoint, csrfToken) {
    if (!navigator.onLine) {
        return { attempted: 0, sent: 0, duplicates: 0, failed: 0, offline: true };
    }

    const items = await pending();
    const summary = { attempted: items.length, sent: 0, duplicates: 0, failed: 0, offline: false };

    for (const item of items) {
        item.status = STATUS.SYNCING;
        await update(item);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken ?? '',
                },
                body: JSON.stringify(item.payload),
            });

            // 201 accepted, 200 duplicate. Both mean the server has it, so the
            // item leaves the queue either way — a duplicate left queued would
            // be retried forever.
            if (response.status === 201 || response.status === 200) {
                const body = await response.json().catch(() => ({}));

                item.status = STATUS.SYNCED;
                item.syncedAt = Date.now();
                item.serverId = body.id ?? null;
                await update(item);

                if (body.status === 'duplicate') {
                    summary.duplicates += 1;
                } else {
                    summary.sent += 1;
                }

                continue;
            }

            // 4xx other than 429 is a permanent problem with this submission;
            // retrying cannot fix a validation error, so it is surfaced to the
            // reporter instead of looping.
            if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                const body = await response.json().catch(() => ({}));

                item.status = STATUS.FAILED;
                item.lastError = body.message ?? `HTTP ${response.status}`;
                await update(item);
                summary.failed += 1;

                continue;
            }

            throw new Error(`HTTP ${response.status}`);
        } catch (error) {
            item.attempts += 1;
            item.lastError = String(error?.message ?? error);
            item.status = item.attempts >= MAX_ATTEMPTS ? STATUS.FAILED : STATUS.PENDING;
            await update(item);

            if (item.status === STATUS.FAILED) {
                summary.failed += 1;
            }

            // Stop on the first transport failure: if the network just dropped,
            // hammering the remaining items wastes battery and achieves nothing.
            if (!navigator.onLine) {
                summary.offline = true;
                break;
            }
        }
    }

    await pruneSynced();

    return summary;
}

/**
 * Ask the service worker to flush when connectivity returns.
 *
 * Background Sync is the right mechanism and works on Chromium. iOS Safari does
 * not implement it at all, which is not a niche gap in the places this platform
 * targets — so the online/visibility listeners in the Alpine component are the
 * real fallback, not an afterthought.
 */
export async function requestBackgroundSync() {
    if (!('serviceWorker' in navigator)) {
        return false;
    }

    try {
        const registration = await navigator.serviceWorker.ready;

        if (!('sync' in registration)) {
            return false;
        }

        await registration.sync.register('qeema-flush-outbox');

        return true;
    } catch {
        return false;
    }
}
