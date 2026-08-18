<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use App\Models\Location;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;

/*
|--------------------------------------------------------------------------
| Public API
|--------------------------------------------------------------------------
|
| The data being open is the product, so these tests guard two things: that the
| endpoints are genuinely unauthenticated, and that every qualifier a consumer
| needs to interpret a figure travels with it.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->basket = $this->country->baskets()->firstOrFail();

    $this->snapshot = IndexSnapshot::factory()->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'basket_id' => $this->basket->id,
        'snapshot_date' => now()->toDateString(),
    ]);
});

describe('openness', function () {
    it('serves every read endpoint without authentication', function () {
        // Constraint C6. A regression adding auth here would break the
        // platform's entire reason for existing.
        foreach ([
            '/api/v1/countries',
            '/api/v1/countries/LY/locations',
            '/api/v1/countries/LY/basket',
            '/api/v1/countries/LY/fx',
            '/api/v1/countries/LY/index/current',
            '/api/v1/countries/LY/coverage',
            "/api/v1/locations/{$this->location->slug}/index",
        ] as $path) {
            $this->getJson($path)->assertOk();
        }
    });

    it('404s for an unknown country rather than erroring', function () {
        $this->getJson('/api/v1/countries/ZZ/index/current')->assertNotFound();
    });
});

describe('every figure carries its qualifiers', function () {
    it('publishes coverage, imputed share and comparability with each snapshot', function () {
        $body = $this->getJson('/api/v1/countries/LY/index/current')->assertOk()->json('data.0');

        expect($body['quality'])->toHaveKeys([
            'coverage', 'imputed_share', 'observed_items', 'total_items', 'label', 'comparable',
        ]);
    });

    it('publishes a confidence interval with the cost', function () {
        $cost = $this->getJson('/api/v1/countries/LY/index/current')->json('data.0.cost');

        expect($cost)->toHaveKeys(['local', 'currency', 'usd', 'confidence_low', 'confidence_high']);
    });

    it('reports exchange rate staleness', function () {
        $fx = $this->getJson('/api/v1/countries/LY/index/current')->json('data.0.exchange_rate');

        expect($fx)->toHaveKeys(['rate', 'type', 'date', 'is_stale'])
            ->and($fx['is_stale'])->toBeBool();
    });

    it('flags every item price as imputed or not', function () {
        // The single most important field in the payload.
        IndexSnapshotItem::factory()->create([
            'index_snapshot_id' => $this->snapshot->id,
            'canonical_item_id' => $this->country->canonicalItems()->firstOrFail()->id,
        ]);
        IndexSnapshotItem::factory()->imputed()->create([
            'index_snapshot_id' => $this->snapshot->id,
            'canonical_item_id' => $this->country->canonicalItems()->skip(1)->firstOrFail()->id,
        ]);

        $items = $this->getJson(
            "/api/v1/locations/{$this->location->slug}/index/".$this->snapshot->snapshot_date->toDateString()
        )->assertOk()->json('data.items');

        expect($items)->toHaveCount(2);

        foreach ($items as $item) {
            expect($item)->toDeclareImputationStatus();
        }
    });

    it('reports zero observations behind an imputed price', function () {
        IndexSnapshotItem::factory()->imputed()->create([
            'index_snapshot_id' => $this->snapshot->id,
            'canonical_item_id' => $this->country->canonicalItems()->firstOrFail()->id,
        ]);

        $item = $this->getJson(
            "/api/v1/locations/{$this->location->slug}/index/".$this->snapshot->snapshot_date->toDateString()
        )->json('data.items.0');

        expect($item['is_imputed'])->toBeTrue()
            ->and($item['observation_count'])->toBe(0)
            ->and($item['imputation_method'])->not->toBeNull();
    });

    describe('what the observation count is allowed to reveal', function () {
        // `observation_count: 1` on an unauthenticated endpoint states that one
        // person reported that product, in that named town, on that day.
        // Repeated daily it is a behavioural fingerprint, and where reporting
        // prices is sensitive that is a risk to the reporter. Small counts are
        // withheld; the price, its interval and the imputation flag are not.
        $publish = function (int $observations) {
            IndexSnapshotItem::factory()->create([
                'index_snapshot_id' => test()->snapshot->id,
                'canonical_item_id' => test()->country->canonicalItems()->firstOrFail()->id,
                'is_imputed' => false,
                'observation_count' => $observations,
            ]);

            return test()->getJson(
                '/api/v1/locations/'.test()->location->slug.'/index/'
                    .test()->snapshot->snapshot_date->toDateString()
            )->json('data.items.0');
        };

        it('withholds a count that would describe a single reporter', function () use ($publish) {
            config()->set('qeema.privacy.min_disclosed_observations', 5);

            $item = $publish(1);

            expect($item['observation_count'])->toBeNull()
                ->and($item['observation_count_disclosure'])->toBe('withheld')
                // Withholding the count must not withhold the measurement. A
                // consumer loses precision on how well supported the price is,
                // never the price.
                ->and($item['unit_price'])->not->toBeNull()
                ->and($item['is_imputed'])->toBeFalse();
        });

        it('states a count large enough to describe a market rather than a person', function () use ($publish) {
            config()->set('qeema.privacy.min_disclosed_observations', 5);

            $item = $publish(12);

            expect($item['observation_count'])->toBe(12)
                ->and($item['observation_count_disclosure'])->toBe('exact');
        });

        it('lets an operator with nobody to protect disclose everything', function () use ($publish) {
            // Synthetic or published-source data has no reporter behind it, and
            // the demo stack sets exactly this.
            config()->set('qeema.privacy.min_disclosed_observations', 1);

            expect($publish(1)['observation_count'])->toBe(1);
        });

        it('never mistakes an imputed row for a withheld one', function () use ($publish) {
            // Zero observations describes nobody, so it passes through however
            // high the threshold is. Blanking it would hide the imputation
            // signal, which matters far more than the count.
            config()->set('qeema.privacy.min_disclosed_observations', 99);

            $item = $publish(0);

            expect($item['observation_count'])->toBe(0)
                ->and($item['observation_count_disclosure'])->toBe('exact');
        });
    });

    it('publishes a null dollar cost rather than omitting the key', function () {
        // A missing key reads as "no data"; an explicit null says "we could not
        // convert this", which is a different and more useful statement.
        $this->snapshot->forceFill(['cost_usd' => null])->save();

        $body = $this->getJson('/api/v1/countries/LY/index/current')->json('data.0');

        expect($body['cost'])->toHaveKey('usd')
            ->and($body['cost']['usd'])->toBeNull();
    });
});

describe('reference data', function () {
    it('publishes the basket with its weights', function () {
        // Weights are a judgement, not a fact. A consumer cannot disagree with
        // the basket composition without seeing it.
        $body = $this->getJson('/api/v1/countries/LY/basket')->assertOk()->json();

        expect($body['items'])->toHaveCount(15)
            ->and($body['items'][0])->toHaveKeys(['code', 'weight', 'quantity', 'unit', 'category']);
    });

    it('publishes both exchange rates and the premium between them', function () {
        FxRate::factory()->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id, 'rate_date' => now()->toDateString()]);

        $row = $this->getJson('/api/v1/countries/LY/fx')->assertOk()->json('data.0');

        expect((float) $row['official'])->toBe(5.0)
            ->and((float) $row['parallel'])->toBe(10.0)
            ->and((float) $row['parallel_premium'])->toBe(1.0);
    });

    it('names which rate the index actually uses', function () {
        $this->getJson('/api/v1/countries/LY/fx')->assertJsonPath('used_by_index', 'parallel');
    });
});

describe('bulk export', function () {
    it('streams a CSV with the licence attached', function () {
        $response = $this->get('/api/v1/countries/LY/export.csv');

        $response->assertOk()
            ->assertHeader('X-Qeema-License', 'CC-BY-4.0');

        expect($response->headers->get('Content-Type'))->toContain('text/csv');
    });

    it('includes the comparability flag in the export', function () {
        // A CSV passed on loses every bit of context the API page carried, so
        // the qualifiers have to be columns.
        $csv = $this->get('/api/v1/countries/LY/export.csv')->streamedContent();

        expect($csv)->toContain('comparable')
            ->and($csv)->toContain('imputed_share')
            ->and($csv)->toContain('fx_is_stale');
    });

    it('omits the HXL row unless it is asked for', function () {
        // The tag row is an ordinary data row to any parser that has not been
        // told about HXL, so emitting it unconditionally would silently change
        // what every existing consumer parses.
        $lines = explode("\n", $this->get('/api/v1/countries/LY/export.csv')->streamedContent());

        expect($lines[0])->toStartWith('date,')
            ->and($lines[1] ?? '')->not->toStartWith('#');
    });

    it('tags the export for the humanitarian data ecosystem when asked', function () {
        $lines = explode("\n", $this->get('/api/v1/countries/LY/export.csv?hxl=1')->streamedContent());

        // Directly beneath the header, which is where HXL-aware tooling looks
        // for it — a tag row anywhere else is not a HXL file.
        expect($lines[0])->toStartWith('date,')
            ->and($lines[1])->toStartWith('#date,')
            ->and($lines[1])->toContain('#value+cost+usd')
            ->and($lines[1])->toContain('#loc+name');
    });

    it('keeps the header, the HXL row and the data rows the same width', function () {
        // The bug this exists to catch: someone adds a column to the query and
        // the header without adding a hashtag, and every HXL consumer's columns
        // shift by one from that point on. Nothing else in the suite would
        // notice, because the file still parses.
        $lines = array_values(array_filter(
            explode("\n", $this->get('/api/v1/countries/LY/export.csv?hxl=1')->streamedContent()),
        ));

        expect(count($lines))->toBeGreaterThan(2);

        $width = count(str_getcsv($lines[0], ',', '"', '\\'));

        foreach ($lines as $number => $line) {
            expect(count(str_getcsv($line, ',', '"', '\\')))
                ->toBe($width, "row {$number} has a different number of columns than the header");
        }
    });
});
