<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;

/*
|--------------------------------------------------------------------------
| Reporter PWA shell
|--------------------------------------------------------------------------
|
| The offline behaviour itself is exercised by Playwright; these cover what the
| server is responsible for — locale negotiation, text direction, and handing
| the client the endpoints it needs.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );
});

describe('serving the app', function () {
    it('is reachable without authentication', function () {
        // Requiring a signup would suppress exactly the participation the
        // platform depends on.
        $this->get('/report')->assertOk();
    });

    it('serves the dashboard at the site root', function (): void {
        // The root used to redirect to the reporter. The public dashboard now
        // holds it: the published data is the product, and it is what a first
        // visitor should land on. The reporter keeps its own route.
        $this->get('/')->assertOk();
        $this->get('/report')->assertOk();
    });

    it('links the web app manifest so it can be installed', function () {
        $this->get('/report')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/manifest.webmanifest', false);
    });

    it('registers the service worker from the bundle, not an inline script', function () {
        $html = $this->get('/report')->getContent();

        // The registration moved out of the view so the page can be served
        // under a policy that forbids inline script. Asserting the *absence* of
        // an inline block is the part worth keeping: an inline <script> here
        // would silently force 'unsafe-inline' back into the CSP.
        expect($html)->not->toMatch('/<script(?![^>]*\ssrc=)(?![^>]*type="application\/json")[^>]*>/i');

        // The bundled entry point is still loaded.
        expect($html)->toContain('reporter');

        $registration = (string) file_get_contents(resource_path('js/reporter.js'));

        expect($registration)->toContain('serviceWorker.register');
    });

    it('hands the client the endpoints it needs', function () {
        // Asserted against the view data rather than the rendered HTML: @js()
        // emits JSON.parse() with unicode-escaped quotes and escaped slashes,
        // and a test coupled to that encoding would break on a Blade change
        // without anything actually being wrong.
        $config = $this->get('/report')->viewData('config');

        expect($config['bootstrapUrl'])->toContain('/api/v1/bootstrap/LY')
            ->and($config['submitUrl'])->toContain('/api/v1/submissions')
            ->and($config['countryCode'])->toBe('LY')
            ->and($config['csrfToken'])->not->toBeEmpty();
    });

    it('passes the translated queue messages to the client', function () {
        // The offline queue reports status with no server round-trip, so its
        // strings have to travel with the page or they would be English-only
        // for an Arabic reporter.
        $config = $this->get('/report?locale=ar')->viewData('config');

        expect($config['messages']['queued'])->toBe(__('reporter.queued', [], 'ar'))
            ->and($config['messages']['synced'])->toContain(':count');
    });

    it('serves the offline fallback page', function () {
        $this->get('/offline')->assertOk();
    });
});

describe('bilingual and right-to-left', function () {
    it('renders right-to-left for Arabic', function () {
        // Direction is derived from the locale rather than a per-country flag,
        // so a country configured with another RTL language works unchanged.
        $this->get('/report?locale=ar')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false);
    });

    it('renders left-to-right for English', function () {
        $this->get('/report?locale=en')
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('lang="en"', false);
    });

    it('translates the interface into Arabic', function () {
        $this->get('/report?locale=ar')
            ->assertSee(__('reporter.title', [], 'ar'), false)
            ->assertSee('أبلغ عن سعر', false);
    });

    it('translates the interface into English', function () {
        $this->get('/report?locale=en')->assertSee('Report a price', false);
    });

    it('offers a switch for every locale the country configures', function () {
        $response = $this->get('/report');

        $response->assertSee('hreflang="ar"', false)
            ->assertSee('hreflang="en"', false);
    });

    it('honours the device language when the country offers it', function () {
        // A reporter should not have to pick their own language on first run.
        $this->get('/report', ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertSee('lang="en"', false);
    });

    it('falls back to the country default for an unsupported language', function () {
        $this->get('/report', ['Accept-Language' => 'ja-JP,ja;q=0.9'])
            ->assertSee('lang="ar"', false);
    });

    it('ignores a locale the country does not configure', function () {
        $this->get('/report?locale=fr')->assertOk()->assertDontSee('lang="fr"', false);
    });
});

describe('accessibility and mobile behaviour', function () {
    it('does not disable pinch zoom', function () {
        // Blocking zoom is a WCAG failure and the sort of thing that quietly
        // ships in a mobile-first build.
        $this->get('/report')->assertDontSee('user-scalable=no', false);
    });

    it('announces connectivity changes to assistive technology', function () {
        $this->get('/report')
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false);
    });

    it('labels every input', function () {
        $response = $this->get('/report')->getContent();

        foreach (['for="location"', 'for="item"', 'for="price"', 'for="quantity"', 'for="unit"'] as $label) {
            expect($response)->toContain($label);
        }
    });
});

describe('static PWA assets', function () {
    it('serves a valid web app manifest', function () {
        $path = public_path('manifest.webmanifest');

        expect(file_exists($path))->toBeTrue();

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['start_url'])->toBe('/report')
            ->and($manifest['display'])->toBe('standalone')
            ->and($manifest['icons'])->not->toBeEmpty();
    });

    it('ships every icon the manifest references', function () {
        /** @var array{icons: list<array{src: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['icons'] as $icon) {
            expect(file_exists(public_path(ltrim($icon['src'], '/'))))
                ->toBeTrue("Missing icon {$icon['src']}");
        }
    });

    it('includes a maskable icon so the installed app looks right on Android', function () {
        /** @var array{icons: list<array{purpose?: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $purposes = array_column($manifest['icons'], 'purpose');

        expect($purposes)->toContain('maskable');
    });

    it('ships a service worker that never replays submissions itself', function () {
        // The worker must not retry writes: only the IndexedDB outbox can
        // guarantee the idempotency key survives a retry, and a worker-side
        // replay could send the same price under a second key.
        $worker = (string) file_get_contents(public_path('sw.js'));

        expect($worker)->toContain("request.method !== 'GET'")
            ->and($worker)->toContain('qeema-flush-outbox');
    });
});
