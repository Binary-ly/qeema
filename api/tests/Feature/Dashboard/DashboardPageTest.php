<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use App\Models\Location;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $attributes
 */
function snapshotFor(Country $country, Location $location, Basket $basket, array $attributes = []): IndexSnapshot
{
    $snapshot = IndexSnapshot::factory()->for($country)->for($location)->for($basket)->create([
        'snapshot_date' => CarbonImmutable::today()->toDateString(),
        'is_stale' => false,
        ...$attributes,
    ]);

    IndexSnapshotItem::factory()->for($snapshot, 'indexSnapshot')->create();

    return $snapshot;
}

beforeEach(function (): void {
    $this->country = Country::factory()->create([
        'code' => 'ZZ',
        'currency_code' => 'ZZD',
        'is_active' => true,
        'locales' => ['en', 'ar'],
        'default_locale' => 'en',
    ]);

    $this->basket = Basket::factory()->for($this->country)->create();
});

describe('rendering', function (): void {
    it('serves the dashboard at the site root', function (): void {
        $this->get('/')->assertOk();
    });

    it('publishes the headline median when locations are comparable', function (): void {
        foreach ([100.0, 200.0, 300.0] as $i => $cost) {
            snapshotFor(
                $this->country,
                Location::factory()->for($this->country)->create(['slug' => "loc-{$i}"]),
                $this->basket,
                ['cost_local' => $cost, 'coverage_pct' => 1.0, 'imputed_share' => 0.0],
            );
        }

        $response = $this->get('/?country=ZZ&locale=en');

        $response->assertOk();
        $response->assertSee('200.00');
        $response->assertSee('ZZD');
    });

    it('still shows the map and table when nothing is comparable', function (): void {
        // The regression this guards: gating the whole page on the median meant
        // that when no basket was fully priced, a reader saw an empty page
        // rather than sixteen locations of real, individually-accurate data.
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'partial', 'name' => 'Partialville']),
            $this->basket,
            ['cost_local' => 120.0, 'coverage_pct' => 0.6, 'imputed_share' => 0.0],
        );

        $response = $this->get('/?country=ZZ&locale=en');

        $response->assertOk();
        $response->assertSee('Partialville');
        $response->assertSee(__('dashboard.no_comparable'));
        $response->assertSee('dash__map-dot--incomparable', escape: false);
    });

    it('says so plainly when no country is configured', function (): void {
        Country::query()->update(['is_active' => false]);

        $this->get('/')->assertOk()->assertSee(__('dashboard.no_data'));
    });

    it('says so plainly when a country has published nothing', function (): void {
        $this->get('/?country=ZZ')->assertOk()->assertSee(__('dashboard.no_data'));
    });
});

describe('honesty of the published figures', function (): void {
    it('marks an incomparable location and does not rank it', function (): void {
        $comparable = Location::factory()->for($this->country)->create(['slug' => 'full', 'name' => 'Fullton']);
        $incomparable = Location::factory()->for($this->country)->create(['slug' => 'part', 'name' => 'Partton']);

        snapshotFor($this->country, $comparable, $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 1.0, 'imputed_share' => 0.0]);

        // Cheaper on paper only because a third of its basket has no price.
        snapshotFor($this->country, $incomparable, $this->basket,
            ['cost_local' => 40.0, 'coverage_pct' => 0.66, 'imputed_share' => 0.0]);

        $response = $this->get('/?country=ZZ&locale=en');

        // The median must come from the comparable location alone. Including
        // the 40.00 would report the country as cheaper than it is, purely
        // because one place is under-reported.
        $response->assertSee('100.00');
        $response->assertSee(__('dashboard.comparable_note'));
    });

    it('shows the estimated share rather than burying it', function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'imputed-heavy']),
            $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 0.5, 'imputed_share' => 0.5],
        );

        $response = $this->get('/?country=ZZ&locale=en');

        $response->assertOk();
        // Comparable — the basket is fully priced — but half of it is estimated,
        // and the interface says so.
        $response->assertSee('50%');
        $response->assertSee(__('dashboard.imputed_explain'));
    });

    it('never presents an imputed figure as observed', function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'x']),
            $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 0.4, 'imputed_share' => 0.6],
        );

        $response = $this->get('/?country=ZZ&locale=en');

        // Quality must not read "good" when most of the basket was estimated.
        $response->assertDontSee('dash__quality--good', escape: false);
    });
});

describe('bilingual and RTL', function (): void {
    it('renders right-to-left for an RTL locale', function (): void {
        $response = $this->get('/?country=ZZ&locale=ar');

        $response->assertOk();
        $response->assertSee('dir="rtl"', escape: false);
        $response->assertSee(__('dashboard.title', [], 'ar'));
    });

    it('renders left-to-right for an LTR locale', function (): void {
        $this->get('/?country=ZZ&locale=en')->assertOk()->assertSee('dir="ltr"', escape: false);
    });

    it('falls back to the country default for an unsupported locale', function (): void {
        // A stray ?locale=fr must not produce a half-translated page.
        $this->get('/?country=ZZ&locale=fr')->assertOk()->assertSee('dir="ltr"', escape: false);
    });

    it('tells the chart script which direction to draw', function (): void {
        $this->get('/?country=ZZ&locale=ar')->assertOk()->assertSee('"rtl":true', escape: false);
    });
});

describe('offline and third-party independence', function (): void {
    it('loads no third-party assets', function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'a']),
            $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 1.0, 'imputed_share' => 0.0],
        );

        $html = $this->get('/?country=ZZ&locale=en')->getContent();

        // Constraint C1. A dashboard that fetches a map tile, a font or a chart
        // library from someone else's server is not self-hostable, and is blank
        // in the low-connectivity places this platform measures.
        foreach (['cdn.', 'unpkg.com', 'jsdelivr', 'googleapis.com', 'fonts.bunny.net', 'tile.openstreetmap'] as $host) {
            expect($html)->not->toContain($host);
        }
    });

    it('renders the map as inline SVG, not a canvas', function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'a', 'name' => 'Alpha']),
            $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 1.0, 'imputed_share' => 0.0],
        );

        $html = $this->get('/?country=ZZ&locale=en')->getContent();

        // A canvas is opaque to assistive technology. Each point being a real
        // element is what puts the map in the accessibility tree at all.
        expect($html)->toContain('<svg')
            ->and($html)->toContain('dash__map-dot')
            ->and($html)->toContain('Alpha');
    });

    it('states every figure in the markup, before any script runs', function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'a', 'name' => 'Alpha']),
            $this->basket,
            ['cost_local' => 137.5, 'coverage_pct' => 1.0, 'imputed_share' => 0.0],
        );

        FxRate::factory()->for($this->country)->create([
            'rate_date' => CarbonImmutable::today()->toDateString(),
            'official_rate' => 4.8,
            'parallel_rate' => 7.2,
        ]);

        $response = $this->get('/?country=ZZ&locale=en');

        // No JavaScript executes in this request. Everything a reader needs is
        // already here.
        $response->assertSee('137.50');
        $response->assertSee('Alpha');
    });
});

describe('accessibility affordances', function (): void {
    beforeEach(function (): void {
        snapshotFor(
            $this->country,
            Location::factory()->for($this->country)->create(['slug' => 'a', 'name' => 'Alpha']),
            $this->basket,
            ['cost_local' => 100.0, 'coverage_pct' => 1.0, 'imputed_share' => 0.0],
        );

        $this->html = $this->get('/?country=ZZ&locale=en')->getContent();
    });

    it('offers a skip link to the main content', function (): void {
        expect($this->html)->toContain('dash__skip')
            ->and($this->html)->toContain('href="#main"');
    });

    it('declares the document language', function (): void {
        expect($this->html)->toContain('lang="en"');
    });

    it('labels every section with a heading', function (): void {
        expect($this->html)->toContain('aria-labelledby="map-h"')
            ->and($this->html)->toContain('aria-labelledby="table-h"');
    });

    it('gives the table proper row and column headers', function (): void {
        expect($this->html)->toContain('scope="col"')
            ->and($this->html)->toContain('scope="row"');
    });

    it('describes the map for a screen reader', function (): void {
        expect($this->html)->toContain('aria-describedby="map-desc"')
            ->and($this->html)->toContain(__('dashboard.map_alt'));
    });

    it('never signals quality by colour alone', function (): void {
        // WCAG 1.4.1. The word is always present; the colour only reinforces it.
        expect($this->html)->toContain(__('dashboard.quality_good'));
    });
});
