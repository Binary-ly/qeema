<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use App\Models\Location;
use App\Services\Dashboard\DashboardData;
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

describe('the sentence a reader came for', function (): void {
    /**
     * One town with a two-item basket: rice priced for the month, flour not.
     *
     * @return array{snapshot: IndexSnapshot, rice: CanonicalItem, flour: CanonicalItem}
     */
    function pricedTown(Country $country, Basket $basket, float $cost = 200.0, bool $imputed = false): array
    {
        $location = Location::factory()->for($country)->create(['slug' => 'alpha', 'name' => 'Alpha', 'name_local' => 'ألفا']);
        $rice = CanonicalItem::factory()->for($country)->create(['code' => 'rice', 'name_en' => 'Rice', 'name_local' => 'أرز']);
        $flour = CanonicalItem::factory()->for($country)->create(['code' => 'flour', 'name_en' => 'Flour', 'name_local' => 'دقيق']);
        BasketItem::factory()->for($basket)->for($rice, 'canonicalItem')->create(['weight' => 0.6, 'quantity' => 2, 'unit_code' => 'kg']);
        BasketItem::factory()->for($basket)->for($flour, 'canonicalItem')->create(['weight' => 0.4, 'quantity' => 5, 'unit_code' => 'kg']);

        $snapshot = IndexSnapshot::factory()->for($country)->for($location)->for($basket)->create([
            'snapshot_date' => CarbonImmutable::today()->toDateString(),
            'is_stale' => false,
            'cost_local' => $cost,
            'coverage_pct' => $imputed ? 0.0 : 0.6,
            'imputed_share' => $imputed ? 0.6 : 0.0,
            'observed_item_count' => $imputed ? 0 : 1,
            'total_item_count' => 2,
        ]);
        IndexSnapshotItem::factory()->for($snapshot, 'indexSnapshot')->for($rice, 'canonicalItem')->create([
            'unit_price_local' => 6.5,
            'quantity' => 2,
            'contribution_local' => 13.0,
            'is_imputed' => $imputed,
        ]);

        return ['snapshot' => $snapshot, 'rice' => $rice, 'flour' => $flour];
    }

    it('sets the basket against the income the country cites', function (): void {
        $this->country->update(['reference_income' => [
            'amount' => 1000,
            'period' => 'month',
            'label_en' => 'the legal minimum monthly wage',
            'label_local' => 'الحد الأدنى للأجر',
            'sources' => [['url' => 'https://example.test/law', 'date' => '2023-05-22', 'says' => 'one thousand']],
        ]]);
        pricedTown($this->country, $this->basket, cost: 200.0);

        $this->get('/?country=ZZ&locale=en')
            ->assertOk()
            ->assertSee('the legal minimum monthly wage')
            ->assertSee('In Alpha, the 1 items with a price come to 200.00 ZZD')
            ->assertSee('data-count="20"', false);

        // The local label leads on the right-to-left page.
        $this->get('/?country=ZZ&locale=ar')->assertOk()->assertSee('الحد الأدنى للأجر');
    });

    it('says nothing about income when the country declares none', function (): void {
        pricedTown($this->country, $this->basket);

        $this->get('/?country=ZZ&locale=en')->assertOk()->assertDontSee('dash__afford');
    });

    it("prices the month's list for the town that has the figures", function (): void {
        pricedTown($this->country, $this->basket);

        $page = $this->get('/?country=ZZ&locale=en')->assertOk();

        // The priced line carries the cost of the month's quantity, not the
        // unit price; the unpriced line is drawn hollow and says so.
        $page->assertSee('In Alpha, this month:')
            ->assertSee('13.00')
            ->assertSee('no price yet')
            ->assertSee('month__item is-hollow', false)
            ->assertSee('1 of 2 priced.');
    });

    it('marks an estimated line as one', function (): void {
        pricedTown($this->country, $this->basket, imputed: true);

        $this->get('/?country=ZZ&locale=en')->assertOk()->assertSee('month__est', false);
    });

    it('opens two doors that keep the language', function (): void {
        pricedTown($this->country, $this->basket);

        $page = $this->get('/?country=ZZ&locale=ar')->assertOk();

        $page->assertSee('door--report', false)
            ->assertSee('door--data', false)
            ->assertSee('عندي سعر')
            ->assertSee('أحتاج الرقم');

        preg_match_all('/class="door door--(?:report|data)" href="([^"]+)"/', $page->getContent(), $matches);

        expect($matches[1])->toHaveCount(2);

        foreach ($matches[1] as $href) {
            expect(html_entity_decode($href))->toContain('locale=ar');
        }
    });

    it('draws the code to hand out from the reporter link itself', function (): void {
        pricedTown($this->country, $this->basket);

        $page = $this->get('/?country=ZZ&locale=en')->assertOk();

        preg_match('/data-qr data-url="([^"]+)"/', $page->getContent(), $match);

        expect($match)->toHaveCount(2)
            ->and(html_entity_decode($match[1]))->toStartWith('http')
            ->and(html_entity_decode($match[1]))->toContain('country=ZZ');
    });
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

describe('which of two names leads', function (): void {
    /*
     * Locations and basket items each carry an English name and a local one,
     * and the page shows both. Which one leads was hardcoded to English in four
     * separate places — the map caption, the map tooltip, the table row and the
     * basket list — so an Arabic reader met every town and every item in Latin
     * script with the name they actually use demoted underneath.
     */
    it('leads with the local name on a right-to-left page and the English one otherwise', function (): void {
        $location = Location::factory()->for($this->country)->create([
            'slug' => 'twonames',
            'name' => 'Fullton',
            'name_local' => 'فلتون',
        ]);

        snapshotFor($this->country, $location, $this->basket, [
            'cost_local' => 100.0,
            'coverage_pct' => 1.0,
            'imputed_share' => 0.0,
        ]);

        $english = (string) $this->get('/?country=ZZ&locale=en')->assertOk()->getContent();
        $arabic = (string) $this->get('/?country=ZZ&locale=ar')->assertOk()->getContent();

        // Both names appear either way — the point is the order, not the
        // presence, so asserting only "contains" would pass on the bug.
        foreach ([$english, $arabic] as $html) {
            expect($html)->toContain('Fullton')->and($html)->toContain('فلتون');
        }

        expect(strpos($english, 'Fullton'))->toBeLessThan(strpos($english, 'فلتون'));
        expect(strpos($arabic, 'فلتون'))->toBeLessThan(strpos($arabic, 'Fullton'));
    });
});

describe('the link preview', function (): void {
    /*
     * Shared into a chat, a feed or an email, the page was a bare URL — no
     * title, no image, nothing to recognise. For most people that preview is
     * the only frame of the platform they will ever see.
     */
    it('carries the tags a preview is built from, on every public page', function (): void {
        foreach (['/?country=ZZ&locale=en', '/report?locale=en', '/docs'] as $path) {
            $html = (string) $this->get($path)->assertOk()->getContent();

            foreach (['property="og:title"', 'property="og:description"', 'property="og:image"', 'name="twitter:card"'] as $tag) {
                expect($html)->toContain($tag);
            }
        }
    });

    it('ships the card image at the size previews expect', function (): void {
        $path = public_path('og.png');

        expect(file_exists($path))->toBeTrue();

        $size = getimagesize($path);

        expect($size)->not->toBeFalse()
            ->and($size[0])->toBe(1200)
            ->and($size[1])->toBe(630);
    });
});

describe('carrying the reader between pages', function (): void {
    /*
     * Locale and country ride in the query string, so a bare route() drops both
     * and the next page falls back to the country's default language. Clicking
     * the logo on the English page answered in Arabic. So did "Report a price",
     * and the API documentation link, and the footer.
     */
    it('keeps the language on every internal page link', function (): void {
        // Default Arabic, reader asked for English: a dropped locale is then a
        // visible change of language rather than a no-op.
        $this->country->update(['default_locale' => 'ar']);

        $location = Location::factory()->for($this->country)->create();
        snapshotFor($this->country, $location, $this->basket);

        $html = (string) $this->get('/?country=ZZ&locale=en')->assertOk()->getContent();

        expect($html)->toContain('lang="en"');

        // Anchors only. `<link rel="alternate" hreflang>` announces the other
        // languages on purpose, and a canonical is not something a reader
        // clicks; neither is an internal page link in the sense this guards.
        preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $html, $matches);

        $internal = collect($matches[1])
            // Page routes only. The JSON and CSV endpoints are documents with
            // their own parameters, and anchors stay on the page they are on.
            ->filter(static function (string $href): bool {
                $href = html_entity_decode($href);

                // Anchors stay on the page they are on, and a bare query string
                // is the language switcher itself — the one control whose job
                // is to change the locale.
                if (str_starts_with($href, '#') || str_starts_with($href, '?')) {
                    return false;
                }

                // `?: '/'` is the whole point of this line. A root URL with no
                // trailing slash — exactly what a bare `route('dashboard')`
                // produces — parses to a null path, so an earlier version of
                // this filter dropped the one link most likely to be wrong and
                // passed while the logo was still resetting the language.
                $path = parse_url($href, PHP_URL_PATH) ?: '/';

                return in_array($path, ['/', '/report', '/docs'], true);
            })
            ->values();

        expect($internal)->not->toBeEmpty();

        $bare = $internal->reject(
            static fn (string $href): bool => str_contains(html_entity_decode($href), 'locale=en'),
        )->values()->all();

        expect($bare)->toBe([]);
    });
});

describe('dating the figures', function (): void {
    /*
     * The publisher rolls a snapshot forward for every calendar day whether or
     * not anything was priced, so "the newest snapshot date" and "the date of
     * the numbers on the page" are different questions. A deployment whose last
     * real observations were months old dated its whole page today, because one
     * location that had never been priced carried a today-dated empty snapshot
     * and won the maximum. The median under that date was four months old.
     */
    it('dates the page by the newest snapshot that priced something', function (): void {
        $priced = Location::factory()->for($this->country)->create();
        $neverPriced = Location::factory()->for($this->country)->create();

        $realDate = CarbonImmutable::today()->subDays(103);

        snapshotFor($this->country, $priced, $this->basket, [
            'snapshot_date' => $realDate->toDateString(),
            'coverage_pct' => 1.0,
        ]);

        // The roll-forward: newer, and empty. Both locations get one, so the
        // priced location's own newer-but-empty row is in the set too.
        foreach ([$priced, $neverPriced] as $location) {
            snapshotFor($this->country, $location, $this->basket, [
                'snapshot_date' => CarbonImmutable::today()->toDateString(),
                'coverage_pct' => 0.0,
                'cost_local' => 0,
                'cost_usd' => null,
                'observed_item_count' => 0,
            ]);
        }

        $data = app(DashboardData::class);
        $headline = $data->headline($this->country, $data->currentSnapshots($this->country));

        expect($headline['as_of'])->toBe($realDate->toDateString());

        $this->get('/')
            ->assertOk()
            ->assertSee(__('dashboard.as_of', ['date' => $realDate->toDateString()]))
            ->assertDontSee(__('dashboard.as_of', ['date' => CarbonImmutable::today()->toDateString()]));
    });

    it('dates nothing at all when nothing has ever been priced', function (): void {
        $location = Location::factory()->for($this->country)->create();

        snapshotFor($this->country, $location, $this->basket, [
            'coverage_pct' => 0.0,
            'cost_local' => 0,
            'cost_usd' => null,
            'observed_item_count' => 0,
        ]);

        $data = app(DashboardData::class);
        $headline = $data->headline($this->country, $data->currentSnapshots($this->country));

        // Null rather than today: there is no measurement to date.
        expect($headline['as_of'])->toBeNull();
    });
});
