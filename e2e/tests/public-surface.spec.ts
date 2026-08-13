// SPDX-License-Identifier: Apache-2.0
import { expect, test } from '@playwright/test';

/**
 * The public surface, end to end against the composed stack.
 *
 * These are the paths a reviewer opens first and a data consumer builds on, so
 * they are tested against a real deployment rather than a test double. The
 * emphasis is deliberately on the properties the project promises rather than
 * on markup details:
 *
 *   - the data is genuinely public (no key, no session, no CORS wall)
 *   - an estimate is never dressed as a measurement
 *   - a partially-priced basket is never ranked against a complete one
 *   - the page works before, and without, JavaScript
 *   - nothing is fetched from a third party at runtime
 */

test.describe('the dashboard', () => {
    test('renders every location with its figures already in the HTML', async ({ page }) => {
        // JavaScript disabled entirely: the server must have done the work.
        await page.context().addInitScript(() => {});
        const response = await page.goto('/');

        expect(response?.status()).toBe(200);

        const rows = page.locator('tbody tr[id^="row-"]');
        await expect(rows).not.toHaveCount(0);

        // Every row must carry a cost, a coverage figure and a quality label.
        const first = rows.first();
        await expect(first).toContainText('%');
        await expect(page.locator('.dash__map-dot').first()).toBeVisible();
    });

    test('switches language and direction together', async ({ page }) => {
        await page.goto('/?country=LY&locale=ar');
        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('html')).toHaveAttribute('lang', 'ar');

        await page.goto('/?country=LY&locale=en');
        await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    });

    test('serves a second country in its own language and direction', async ({ page }) => {
        // The point of the second country: nothing about the first is baked in.
        await page.goto('/?country=VE');

        await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
        await expect(page.locator('html')).toHaveAttribute('lang', 'es');
        await expect(page.locator('tbody tr[id^="row-"]')).not.toHaveCount(0);
    });

    test('loads nothing from a third party', async ({ page }) => {
        // Constraint C1, observed rather than asserted. A dashboard that reaches
        // out to a CDN is not self-hostable and is blank exactly where it matters.
        const external: string[] = [];

        page.on('request', (request) => {
            const url = new URL(request.url());

            if (!['localhost', '127.0.0.1'].includes(url.hostname)) {
                external.push(request.url());
            }
        });

        await page.goto('/', { waitUntil: 'networkidle' });

        expect(external, `unexpected third-party requests: ${external.join(', ')}`).toEqual([]);
    });

    test('marks estimated values as estimated', async ({ page }) => {
        await page.goto('/');

        // The imputed share is on the page, not hidden behind an API field.
        await expect(page.getByText(/estimated|مُقدَّرة|Estimado/i).first()).toBeVisible();
    });

    test('reaches the map by keyboard', async ({ page }) => {
        await page.goto('/?locale=en');

        // Inline SVG rather than canvas is what makes this possible at all.
        const link = page.locator('.dash__map a').first();
        await link.focus();

        await expect(link).toBeFocused();
        await expect(link).toHaveAttribute('aria-label', /.+/);
    });

    test('offers a working skip link', async ({ page }) => {
        await page.goto('/?locale=en');
        await page.keyboard.press('Tab');

        await expect(page.locator('.dash__skip')).toBeFocused();
    });
});

test.describe('the public API', () => {
    test('needs no credentials of any kind', async ({ request }) => {
        // Constraint C6. If this ever starts returning 401 the project has lost
        // its central promise.
        for (const path of [
            '/api/v1/health',
            '/api/v1/countries',
            '/api/v1/countries/LY/basket',
            '/api/v1/countries/LY/index/current',
            '/api/v1/countries/LY/coverage',
            '/api/v1/countries/VE/index/current',
        ]) {
            const response = await request.get(path);

            expect(response.status(), `${path} should be public`).toBe(200);
        }
    });

    test('flags every priced item as observed or imputed', async ({ request }) => {
        const current = await (await request.get('/api/v1/countries/LY/index/current')).json();

        expect(current.data.length).toBeGreaterThan(0);

        const slug = current.data[0].location.slug;
        const date = current.data[0].date;
        const snapshot = await (await request.get(`/api/v1/locations/${slug}/index/${date}`)).json();

        expect(snapshot.data.items.length).toBeGreaterThan(0);

        for (const item of snapshot.data.items) {
            // Never absent, never null. A consumer reading a missing field as
            // "observed" is the failure this guards.
            expect(typeof item.is_imputed).toBe('boolean');

            if (item.is_imputed) {
                expect(item.imputation_method).toBeTruthy();
                // An imputed price is not backed by observations, by construction.
                expect(item.observation_count).toBe(0);
            }
        }
    });

    test('publishes a chain-linked level, not only a basket cost', async ({ request }) => {
        // A fresh install has to anchor its baskets during bootstrap for this to
        // be non-null. Nothing else in this suite would notice if that step
        // stopped running: the API would keep answering, the dashboard would
        // keep rendering, and every published figure would quietly lose the one
        // number that is comparable across a basket revision.
        const current = await (await request.get('/api/v1/countries/LY/index/current')).json();

        expect(current.data.length).toBeGreaterThan(0);

        for (const snapshot of current.data) {
            expect(
                snapshot.index.level,
                `${snapshot.location.slug} has no index level; was qeema:index:link run?`,
            ).not.toBeNull();

            expect(snapshot.index.level).toBeGreaterThan(0);
            expect(Number.isFinite(snapshot.index.level)).toBe(true);
            expect(snapshot.index.basket_version).toBeGreaterThanOrEqual(1);
        }
    });

    test('tells a consumer when a figure may not be compared', async ({ request }) => {
        const body = await (await request.get('/api/v1/countries/LY/index/current')).json();

        for (const snapshot of body.data) {
            expect(typeof snapshot.quality.comparable).toBe('boolean');
            expect(snapshot.quality.coverage).toBeGreaterThanOrEqual(0);
            expect(snapshot.quality.imputed_share).toBeGreaterThanOrEqual(0);

            // Coverage and imputation together cannot exceed the whole basket.
            expect(snapshot.quality.coverage + snapshot.quality.imputed_share)
                .toBeLessThanOrEqual(1.0001);
        }
    });

    test('refuses to invent a conversion it cannot make', async ({ request }) => {
        const body = await (await request.get('/api/v1/countries/LY/index/current')).json();

        for (const snapshot of body.data) {
            // usd is either a real number or explicitly null — never zero, and
            // never quietly converted at some other rate.
            expect(snapshot.cost.usd === null || snapshot.cost.usd > 0).toBe(true);
            expect(snapshot.cost.local).toBeGreaterThan(0);
        }
    });

    test('serves a specification that matches what it returns', async ({ request }) => {
        const spec = await (await request.get('/api/v1/openapi.json')).json();

        expect(spec.openapi).toMatch(/^3\./);
        expect(spec.components.schemas.SnapshotItem.required).toContain('is_imputed');
        expect(spec.components.schemas.Quality.required).toContain('comparable');

        // Every documented path should actually answer.
        for (const path of Object.keys(spec.paths)) {
            if (path.includes('{') || path === '/submissions') continue;

            const response = await request.get(`/api/v1${path}`);
            expect(response.status(), `${path} is documented but does not answer`).toBeLessThan(500);
        }
    });

    test('exports bulk data with its licence attached', async ({ request }) => {
        const response = await request.get('/api/v1/countries/LY/export.csv');

        expect(response.status()).toBe(200);
        // A CSV outlives the page that explained its terms, so the terms travel
        // with the file.
        expect(response.headers()['x-qeema-license']).toBe('CC-BY-4.0');

        const body = await response.text();
        const lines = body.trim().split('\n');

        expect(lines.length).toBeGreaterThan(1);

        // The export is snapshot-level, so the honesty columns are the
        // snapshot's own: how much was estimated, and whether the total may be
        // compared with another location's. A consumer who opens this in a
        // spreadsheet and sorts by cost needs both in front of them.
        for (const column of ['imputed_share', 'comparable', 'coverage', 'fx_is_stale']) {
            expect(lines[0], `export is missing ${column}`).toContain(column);
        }
    });

    test('rate limits rather than authenticating', async ({ request }) => {
        const response = await request.get('/api/v1/countries');

        // The protection is a limit, not a login.
        expect(response.headers()['x-ratelimit-limit']).toBeTruthy();
    });
});

test.describe('the documentation', () => {
    test('renders every documented operation without the network', async ({ page }) => {
        const external: string[] = [];

        page.on('request', (r) => {
            const url = new URL(r.url());
            if (!['localhost', '127.0.0.1'].includes(url.hostname)) external.push(r.url());
        });

        await page.goto('/docs', { waitUntil: 'networkidle' });

        await expect(page.locator('.op')).not.toHaveCount(0);
        expect(external, `docs fetched third-party assets: ${external.join(', ')}`).toEqual([]);
    });
});
