// SPDX-License-Identifier: Apache-2.0
import { expect, test, type Page } from '@playwright/test';

/**
 * The offline path.
 *
 * This is the claim the reporter app lives or dies on: a price entered with no
 * connection must survive, reach the server when signal returns, and arrive
 * exactly once. Everything else in the app is replaceable; this is not.
 *
 * These run against the composed stack, because a service worker plus an
 * IndexedDB outbox behaves differently under a dev server than in a built
 * deployment — and the built deployment is what a reviewer sees.
 */

/** Read the outbox directly, so assertions do not depend on UI wording. */
async function outbox(page: Page) {
    return page.evaluate(async () => {
        const db: IDBDatabase = await new Promise((resolve, reject) => {
            const request = indexedDB.open('qeema-reporter', 1);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        return new Promise<Array<Record<string, unknown>>>((resolve, reject) => {
            const tx = db.transaction('outbox', 'readonly');
            const req = tx.objectStore('outbox').getAll();
            req.onsuccess = () => resolve(req.result ?? []);
            req.onerror = () => reject(req.error);
        });
    });
}

/** Fill the form and tap save. */
async function enterPrice(page: Page, price: string) {
    await page.selectOption('#location', { index: 1 });
    await page.locator('.item-list__button').first().click();
    await page.fill('#price', price);
    await page.getByRole('button', { name: /save price|حفظ السعر/i }).click();
}

test.beforeEach(async ({ page }) => {
    await page.goto('/report?locale=en');
    await expect(page.locator('.item-list__button').first()).toBeVisible();

    // A service worker does not control the page that registered it — it takes
    // over on the next navigation. So the app genuinely must be opened once
    // with a connection before it can survive losing one, and the setup here
    // mirrors that rather than papering over it. The UI says as much when no
    // cached catalogue exists ("Connect once to set up").
    await page.waitForFunction(
        async () => {
            const registration = await navigator.serviceWorker?.getRegistration();
            return registration?.active != null;
        },
        null,
        { timeout: 20_000 },
    );

    await page.reload();
    await page.waitForFunction(() => navigator.serviceWorker.controller != null, null, {
        timeout: 20_000,
    });
    await expect(page.locator('.item-list__button').first()).toBeVisible();
});

test('the app shell loads and is usable with no connection', async ({ page, context }) => {
    await context.setOffline(true);
    await page.reload();

    // The substantive claim: with the network gone, the service worker still
    // serves the shell and the cached catalogue still populates the pickers, so
    // a reporter can complete a submission.
    await expect(page.locator('#location')).toBeVisible();
    await expect(page.locator('.item-list__button').first()).toBeVisible();
    await expect(page.locator('#price')).toBeEditable();
});

test('the status indicator follows connectivity', async ({ page, context }) => {
    // Asserted by driving the browser's own connectivity events rather than
    // by reading state after an offline reload: Playwright's offline emulation
    // does not reliably flip navigator.onLine for an already-loaded document,
    // and a test that depended on that would be asserting the emulation rather
    // than the application.
    await expect(page.locator('.reporter__status')).toHaveClass(/is-online/);

    await context.setOffline(true);
    await page.evaluate(() => window.dispatchEvent(new Event('offline')));
    await expect(page.locator('.reporter__status')).toHaveClass(/is-offline/);

    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await expect(page.locator('.reporter__status')).toHaveClass(/is-online/);
});

test('a price entered offline is kept and sent on reconnect', async ({ page, context }) => {
    await context.setOffline(true);
    await page.reload();

    await enterPrice(page, '12.75');

    // Held locally, not lost.
    await expect.poll(async () => (await outbox(page)).length).toBe(1);
    const queued = await outbox(page);
    expect(queued[0].status).toBe('pending');
    expect((queued[0].payload as Record<string, unknown>).client_idempotency_key).toBeTruthy();

    // Signal returns.
    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect
        .poll(async () => (await outbox(page)).filter((i) => i.status === 'synced').length, {
            timeout: 20_000,
        })
        .toBe(1);
});

test('several offline entries all survive and sync together', async ({ page, context }) => {
    await context.setOffline(true);
    await page.reload();

    for (const price of ['5.50', '9.25', '31.00']) {
        await enterPrice(page, price);
    }

    await expect.poll(async () => (await outbox(page)).length).toBe(3);

    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect
        .poll(async () => (await outbox(page)).filter((i) => i.status === 'synced').length, {
            timeout: 30_000,
        })
        .toBe(3);
});

test('replaying the queue does not send the same price twice', async ({ page, context }) => {
    // The property that makes a flaky connection harmless rather than a silent
    // distortion of a published figure.
    await context.setOffline(true);
    await page.reload();
    await enterPrice(page, '77.25');

    const item = (await outbox(page))[0];
    const payload = item.payload as Record<string, unknown>;

    // Take the item out of the queue before restoring the network. What is
    // under test is the *server's* handling of a replay, and leaving the item
    // queued makes the app a third sender racing the two below: regaining
    // connectivity triggers its own flush, which posts this very payload, so
    // the "first" send here would sometimes come back 200 duplicate instead of
    // 201 accepted. That is the flake, and it was in the test rather than in
    // the platform.
    await page.evaluate(async () => {
        const db: IDBDatabase = await new Promise((resolve, reject) => {
            const request = indexedDB.open('qeema-reporter', 1);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        await new Promise((resolve, reject) => {
            const tx = db.transaction('outbox', 'readwrite');
            tx.objectStore('outbox').clear();
            tx.oncomplete = () => resolve(null);
            tx.onerror = () => reject(tx.error);
        });
    });

    await context.setOffline(false);
    await expect.poll(async () => (await outbox(page)).length).toBe(0);

    // Send the very same payload twice, exactly as a retry would.
    const results = await page.evaluate(async (body) => {
        const send = () =>
            fetch('/api/v1/submissions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '',
                },
                body: JSON.stringify(body),
            }).then(async (r) => ({ status: r.status, body: await r.json() }));

        const first = await send();
        const second = await send();

        return { first, second };
    }, payload);

    expect(results.first.status).toBe(201);
    expect(results.first.body.status).toBe('accepted');

    // The replay is acknowledged, not rejected: a 4xx would leave the item
    // stuck in the queue being retried forever.
    expect(results.second.status).toBe(200);
    expect(results.second.body.status).toBe('duplicate');
    expect(results.second.body.id).toBe(results.first.body.id);
});

test('the reporter identity is stable across reloads', async ({ page }) => {
    const before = await page.evaluate(() => localStorage.getItem('qeema.reporter_ref'));
    await page.reload();
    const after = await page.evaluate(() => localStorage.getItem('qeema.reporter_ref'));

    expect(before).toBeTruthy();
    expect(after).toBe(before);
});

test('the interface renders right-to-left in Arabic', async ({ page }) => {
    await page.goto('/report?locale=ar');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');

    // The price field stays left-to-right inside an RTL layout: a number is not
    // bidirectional text, and mirroring it would render prices wrongly.
    await expect(page.locator('#price')).toHaveCSS('direction', 'ltr');
});

test('the app is installable', async ({ page, request }) => {
    const manifestHref = await page.locator('link[rel=manifest]').getAttribute('href');
    expect(manifestHref).toBe('/manifest.webmanifest');

    const manifest = await request.get(manifestHref!);
    expect(manifest.ok()).toBeTruthy();

    const body = await manifest.json();
    expect(body.start_url).toBe('/report');
    expect(body.display).toBe('standalone');
});
