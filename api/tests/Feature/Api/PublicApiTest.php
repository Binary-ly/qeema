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
});
