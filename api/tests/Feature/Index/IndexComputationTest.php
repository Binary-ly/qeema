<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Services\Fx\FxRateResolver;
use App\Services\Index\IndexCalculator;
use App\Services\Index\IndexStaleness;
use App\Services\Index\ItemImputer;
use App\Services\Index\PriceEstimator;
use App\Services\Ml\FakeMlClient;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Index computation
|--------------------------------------------------------------------------
|
| The published figure. Expected values are hand-computed rather than asserted
| against whatever the code returns, because a test that accepts the
| implementation's own answer proves only that it is consistent.
|
*/

const DAY = '2026-03-10';

beforeEach(function () {
    $this->country = Country::factory()->create([
        'currency_code' => 'XTS',
        'index_config' => [
            'observation_window_days' => 7,
            'recency_half_life_days' => 3,
            'min_observations_for_ci' => 3,
            // Small but sufficient: the interval only needs to be well-formed,
            // and 500 draws per item makes the suite needlessly slow.
            'bootstrap_draws' => 200,
            'base_date' => null,
        ],
        'fx_config' => ['provider' => 'manual', 'rate_type' => 'parallel', 'max_staleness_days' => 7],
    ]);

    $this->location = Location::factory()->create(['country_id' => $this->country->id]);
    $this->basket = Basket::factory()->create([
        'country_id' => $this->country->id,
        'effective_from' => '2026-01-01',
    ]);

    $this->rice = CanonicalItem::factory()->create([
        'country_id' => $this->country->id,
        'code' => 'rice',
        'default_unit_code' => 'kg',
    ]);
    $this->oil = CanonicalItem::factory()->create([
        'country_id' => $this->country->id,
        'code' => 'oil',
        'default_unit_code' => 'l',
    ]);
});

function priceOn(CanonicalItem $item, float $perBaseUnit, string $date, float $reputation = 1.0): PriceObservation
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
    ]);

    return PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'canonical_item_id' => $item->id,
        'normalized_price_per_base_unit' => $perBaseUnit,
        'observed_on' => $date,
        'observed_at' => $date.' 12:00:00',
        'reputation_at_time' => $reputation,
    ]);
}

function basketOf(array $entries): void
{
    foreach ($entries as [$item, $weight, $quantity, $unit]) {
        BasketItem::factory()->create([
            'basket_id' => test()->basket->id,
            'canonical_item_id' => $item->id,
            'weight' => $weight,
            'quantity' => $quantity,
            'unit_code' => $unit,
        ]);
    }
}

function computeIndex(): IndexSnapshot
{
    return (new IndexCalculator)->calculate(
        test()->country,
        test()->location,
        test()->basket,
        CarbonImmutable::parse(DAY),
    );
}

describe('the weighted median', function () {
    it('returns the middle value with equal weights', function () {
        expect(PriceEstimator::weightedMedian([10.0, 20.0, 30.0], [1.0, 1.0, 1.0]))->toBe(20.0);
    });

    it('follows the weight, not the count', function () {
        // Three cheap observations from barely-trusted reporters must not
        // outvote one from a trusted reporter carrying most of the weight.
        expect(PriceEstimator::weightedMedian([10.0, 10.0, 10.0, 50.0], [0.1, 0.1, 0.1, 10.0]))
            ->toBe(50.0);
    });

    it('takes the midpoint when weight splits exactly in half', function () {
        expect(PriceEstimator::weightedMedian([10.0, 20.0], [1.0, 1.0]))->toBe(15.0);
    });

    it('ignores an extreme outlier, unlike a mean', function () {
        // The mean here is 252.5; the median is unmoved.
        expect(PriceEstimator::weightedMedian([10.0, 10.0, 10.0, 980.0], [1.0, 1.0, 1.0, 1.0]))
            ->toBe(10.0);
    });

    it('returns zero when all weights are zero', function () {
        expect(PriceEstimator::weightedMedian([10.0, 20.0], [0.0, 0.0]))->toBe(0.0);
    });
});

describe('costing the basket', function () {
    it('multiplies quantity by price, not weight by price', function () {
        // 2 kg at 10.00 plus 3 l at 5.00 = 35.00. Weights sum to 1 and must not
        // appear in the cost at all.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);
        priceOn($this->oil, 5.0, DAY);

        expect(computeIndex()->cost_local)->toBe(35.0);
    });

    it('reports coverage by weight, not by item count', function () {
        // One of two items missing, but it carries 60% of the weight — a
        // count-based figure would say 50%.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->oil, 5.0, DAY);

        $snapshot = computeIndex();

        expect($snapshot->coverage_pct)->toBe(0.4)
            // No imputer here, so the missing 60% is genuinely absent rather
            // than estimated, and neither share accounts for it. The gap
            // between the two shares and 1.0 is what marks the basket
            // incomplete.
            ->and($snapshot->imputed_share)->toBe(0.0)
            ->and($snapshot->observed_item_count)->toBe(1)
            ->and($snapshot->total_item_count)->toBe(2);
    });

    it('uses observations from within the window', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 10.0, '2026-03-06');

        expect(computeIndex()->cost_local)->toBe(10.0);
    });

    it('ignores observations outside the window', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 10.0, '2026-02-01');

        expect(computeIndex()->coverage_pct)->toBe(0.0);
    });

    it('ignores invalidated observations', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 10.0, DAY)->forceFill(['is_valid' => false])->save();

        expect(computeIndex()->coverage_pct)->toBe(0.0);
    });

    it('weights a recent observation above an older one', function () {
        // Same reputation, three-day half-life: today's 10.00 carries twice the
        // weight of the 20.00 from three days ago, so the median follows it.
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 20.0, '2026-03-07');
        priceOn($this->rice, 10.0, DAY);

        expect(computeIndex()->cost_local)->toBe(10.0);
    });

    it('produces an interval that brackets the point estimate', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        foreach ([9.0, 10.0, 11.0, 10.5, 9.5] as $price) {
            priceOn($this->rice, $price, DAY);
        }

        $snapshot = computeIndex();

        expect($snapshot->ci_low_local)->toBeLessThanOrEqual($snapshot->cost_local)
            ->and($snapshot->ci_high_local)->toBeGreaterThanOrEqual($snapshot->cost_local);
    });

    it('records provenance back to the observations behind each price', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        $observation = priceOn($this->rice, 10.0, DAY);

        $item = computeIndex()->items()->firstOrFail();

        expect($item->source_observation_ids)->toContain($observation->id)
            ->and($item->is_imputed)->toBeFalse();
    });

    it('is idempotent — recomputing upserts rather than duplicating', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 10.0, DAY);

        computeIndex();
        computeIndex();

        expect(IndexSnapshot::query()->count())->toBe(1)
            ->and(IndexSnapshot::query()->firstOrFail()->items()->count())->toBe(1);
    });

    it('reproduces the same interval on recomputation', function () {
        // Deterministic seeds: a published interval must not wobble because the
        // snapshot was recomputed.
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        foreach ([9.0, 10.0, 11.0, 12.0] as $price) {
            priceOn($this->rice, $price, DAY);
        }

        $first = computeIndex()->ci_low_local;
        $second = computeIndex()->ci_low_local;

        expect($second)->toBe($first);
    });
});

describe('exchange rate resolution', function () {
    beforeEach(function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        priceOn($this->rice, 100.0, DAY);
    });

    it('converts at the parallel rate for the day', function () {
        FxRate::factory()->on(DAY)->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id]);

        $snapshot = computeIndex();

        expect($snapshot->cost_usd)->toBe(10.0)
            ->and($snapshot->fx_is_stale)->toBeFalse();
    });

    it('falls back to an earlier rate and flags it stale', function () {
        FxRate::factory()->on('2026-03-08')->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id]);

        $snapshot = computeIndex();

        expect($snapshot->cost_usd)->toBe(10.0)
            ->and($snapshot->fx_is_stale)->toBeTrue()
            ->and($snapshot->fx_rate_date->toDateString())->toBe('2026-03-08');
    });

    it('refuses a rate older than the configured horizon', function () {
        // Publishing a dollar figure from a three-week-old rate in a currency
        // that moves several percent a month would be confidently wrong.
        FxRate::factory()->on('2026-02-10')->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id]);

        $snapshot = computeIndex();

        expect($snapshot->cost_usd)->toBeNull()
            ->and($snapshot->cost_local)->toBe(100.0);
    });

    it('never uses a rate from after the snapshot date', function () {
        FxRate::factory()->on('2026-03-20')->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id]);

        expect(computeIndex()->cost_usd)->toBeNull();
    });

    it('publishes a null dollar cost rather than an unconverted one', function () {
        expect(computeIndex()->cost_usd)->toBeNull();
    });

    it('honours a country configured to use the official rate', function () {
        $this->country->forceFill([
            'fx_config' => ['provider' => 'manual', 'rate_type' => FxRateResolver::TYPE_OFFICIAL, 'max_staleness_days' => 7],
        ])->save();

        FxRate::factory()->on(DAY)->withRates(official: 5.0, parallel: 10.0)
            ->create(['country_id' => $this->country->id]);

        expect(computeIndex()->cost_usd)->toBe(20.0);
    });
});

describe('correcting a historical observation', function () {
    it('marks the affected window stale', function () {
        // The phase's stated acceptance criterion. An observation is evidence
        // for every snapshot in the window after it, not just its own day.
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        $observation = priceOn($this->rice, 10.0, DAY);

        foreach (['2026-03-10', '2026-03-13', '2026-03-17', '2026-03-25'] as $date) {
            IndexSnapshot::factory()->on($date)->create([
                'country_id' => $this->country->id,
                'location_id' => $this->location->id,
                'basket_id' => $this->basket->id,
                'is_stale' => false,
            ]);
        }

        (new IndexStaleness)->markAffectedBy($observation);

        $stale = IndexSnapshot::query()->stale()->pluck('snapshot_date')
            ->map(fn ($d) => $d->toDateString())->sort()->values()->all();

        // The seven-day window covers the 10th to the 17th; the 25th is beyond
        // any influence and must be left alone.
        expect($stale)->toBe(['2026-03-10', '2026-03-13', '2026-03-17']);
    });

    it('marks snapshots stale automatically when an observation is invalidated', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        $observation = priceOn($this->rice, 10.0, DAY);

        $snapshot = computeIndex();
        expect($snapshot->is_stale)->toBeFalse();

        $observation->forceFill(['is_valid' => false])->save();

        expect($snapshot->fresh()->is_stale)->toBeTrue();
    });

    it('changes the published figure once recomputed', function () {
        // End to end: a wrong price is published, corrected, and the published
        // number actually moves. A correction that never reaches the figure is
        // worse than no correction, because the error is now dated and hidden.
        basketOf([[$this->rice, 1.0, 2.0, 'kg']]);
        $wrong = priceOn($this->rice, 100.0, DAY);

        $before = computeIndex();
        expect($before->cost_local)->toBe(200.0);

        // The reporter had entered a price per gram, not per kilo.
        $corrected = priceOn($this->rice, 10.0, DAY);
        $wrong->supersedeWith($corrected);

        expect($before->fresh()->is_stale)->toBeTrue();

        $after = computeIndex();

        expect($after->cost_local)->toBe(20.0)
            ->and($after->is_stale)->toBeFalse()
            ->and(IndexSnapshot::query()->count())->toBe(1);
    });

    it('does not mark snapshots stale for an unrelated column change', function () {
        // Touching an unrelated field should not trigger a week of recomputation.
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);
        $observation = priceOn($this->rice, 10.0, DAY);
        $snapshot = computeIndex();

        $observation->forceFill(['currency_code' => 'XTS'])->save();

        expect($snapshot->fresh()->is_stale)->toBeFalse();
    });

    it('drains the stale queue oldest first', function () {
        basketOf([[$this->rice, 1.0, 1.0, 'kg']]);

        foreach (['2026-03-12', '2026-03-10', '2026-03-11'] as $date) {
            IndexSnapshot::factory()->on($date)->needingRecomputation()->create([
                'country_id' => $this->country->id,
                'location_id' => $this->location->id,
                'basket_id' => $this->basket->id,
            ]);
        }

        $order = (new IndexStaleness)->pending()
            ->pluck('snapshot_date')->map(fn ($d) => $d->toDateString())->all();

        expect($order)->toBe(['2026-03-10', '2026-03-11', '2026-03-12']);
    });
});

describe('imputation fills the basket', function () {
    it('leaves the basket partial when no imputer is configured', function () {
        // A deployment that cannot impute publishes a partial basket and says
        // so, rather than publishing nothing.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);

        $snapshot = computeIndex();

        expect($snapshot->cost_local)->toBe(20.0)
            // The oil was neither observed nor imputed, so it counts toward
            // neither share and the basket is visibly incomplete.
            ->and($snapshot->coverage_pct)->toBe(0.6)
            ->and($snapshot->imputed_share)->toBe(0.0)
            ->and($snapshot->isComparable())->toBeFalse();
    });

    it('treats an imputed basket as comparable, because that is what imputation is for', function () {
        // Regression. `isComparable()` originally read `imputed_share <= 0`,
        // which was accidentally correct only while nothing was ever imputed.
        // Once Phase 8 began filling gaps, every snapshot gained an imputed
        // share and the rule started reporting `comparable: false` for 46 of 48
        // published snapshots — including baskets that were fully priced.
        //
        // The consequence was not cosmetic: the public API told consumers not
        // to compare almost anything, and the dashboard headline, which is a
        // median across comparable locations, computed over nearly nothing.
        //
        // Imputation is precisely what makes a sparse location comparable. The
        // uncertainty it introduces is reported by `imputed_share` and
        // `qualityLabel()`, which is the right place for it.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);
        $elsewhere = Location::factory()->create(['country_id' => $this->country->id]);
        PriceObservation::factory()->create([
            'submission_id' => Submission::factory()->create([
                'country_id' => $this->country->id, 'location_id' => $elsewhere->id,
            ])->id,
            'country_id' => $this->country->id,
            'location_id' => $elsewhere->id,
            'canonical_item_id' => $this->oil->id,
            'normalized_price_per_base_unit' => 5.0,
            'observed_on' => DAY,
            'observed_at' => DAY.' 12:00:00',
        ]);

        $snapshot = (new IndexCalculator(
            imputer: new ItemImputer(new FakeMlClient),
        ))->calculate($this->country, $this->location, $this->basket, CarbonImmutable::parse(DAY));

        expect($snapshot->imputed_share)->toBeGreaterThan(0.0)
            ->and($snapshot->coverage_pct + $snapshot->imputed_share)->toBeGreaterThanOrEqual(0.999)
            ->and($snapshot->isComparable())->toBeTrue()
            // Comparable, but the reader is still told how much was estimated.
            ->and($snapshot->qualityLabel())->not->toBe('good');
    });

    it('fills a missing item and flags it as imputed', function () {
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);
        // Another location reports the oil, so there is context to impute from.
        $elsewhere = Location::factory()->create(['country_id' => $this->country->id]);
        PriceObservation::factory()->create([
            'submission_id' => Submission::factory()->create([
                'country_id' => $this->country->id, 'location_id' => $elsewhere->id,
            ])->id,
            'country_id' => $this->country->id,
            'location_id' => $elsewhere->id,
            'canonical_item_id' => $this->oil->id,
            'normalized_price_per_base_unit' => 5.0,
            'observed_on' => DAY,
            'observed_at' => DAY.' 12:00:00',
        ]);

        $calculator = new IndexCalculator(
            imputer: new ItemImputer(new FakeMlClient),
        );

        $snapshot = $calculator->calculate(
            $this->country, $this->location, $this->basket, CarbonImmutable::parse(DAY),
        );

        $imputed = $snapshot->items()->imputed()->first();

        expect($imputed)->not->toBeNull()
            ->and($imputed->canonical_item_id)->toBe($this->oil->id)
            ->and($imputed->imputation_method)->not->toBeNull()
            ->and($imputed->observation_count)->toBe(0);
    });

    it('includes the imputed item in the basket cost', function () {
        // 2 kg rice at 10.00 = 20.00, plus 3 l oil imputed at 5.00 = 15.00.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);
        $elsewhere = Location::factory()->create(['country_id' => $this->country->id]);
        PriceObservation::factory()->create([
            'submission_id' => Submission::factory()->create([
                'country_id' => $this->country->id, 'location_id' => $elsewhere->id,
            ])->id,
            'country_id' => $this->country->id,
            'location_id' => $elsewhere->id,
            'canonical_item_id' => $this->oil->id,
            'normalized_price_per_base_unit' => 5.0,
            'observed_on' => DAY,
            'observed_at' => DAY.' 12:00:00',
        ]);

        $snapshot = (new IndexCalculator(
            imputer: new ItemImputer(new FakeMlClient),
        ))->calculate($this->country, $this->location, $this->basket, CarbonImmutable::parse(DAY));

        expect($snapshot->cost_local)->toBe(35.0)
            ->and($snapshot->imputed_share)->toBe(0.4);
    });

    it('never marks an imputed value as observed', function () {
        // The invariant the whole platform's credibility rests on.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);
        $elsewhere = Location::factory()->create(['country_id' => $this->country->id]);
        PriceObservation::factory()->create([
            'submission_id' => Submission::factory()->create([
                'country_id' => $this->country->id, 'location_id' => $elsewhere->id,
            ])->id,
            'country_id' => $this->country->id,
            'location_id' => $elsewhere->id,
            'canonical_item_id' => $this->oil->id,
            'normalized_price_per_base_unit' => 5.0,
            'observed_on' => DAY,
            'observed_at' => DAY.' 12:00:00',
        ]);

        $snapshot = (new IndexCalculator(
            imputer: new ItemImputer(new FakeMlClient),
        ))->calculate($this->country, $this->location, $this->basket, CarbonImmutable::parse(DAY));

        foreach ($snapshot->items as $item) {
            if ($item->is_imputed) {
                expect($item->observation_count)->toBe(0)
                    ->and($item->source_observation_ids)->toBe([]);
            } else {
                expect($item->imputation_method)->toBeNull()
                    ->and($item->observation_count)->toBeGreaterThan(0);
            }
        }
    });

    it('leaves the basket partial when the ML service is unavailable', function () {
        // A silently completed basket would be worse than an honestly partial
        // one, because nothing would indicate the numbers were invented.
        basketOf([[$this->rice, 0.6, 2.0, 'kg'], [$this->oil, 0.4, 3.0, 'l']]);
        priceOn($this->rice, 10.0, DAY);

        $snapshot = (new IndexCalculator(
            imputer: new ItemImputer(
                (new FakeMlClient)->pretendUnavailable(),
            ),
        ))->calculate($this->country, $this->location, $this->basket, CarbonImmutable::parse(DAY));

        expect($snapshot->cost_local)->toBe(20.0)
            ->and($snapshot->items()->imputed()->count())->toBe(0)
            // Nothing was imputed, so nothing is reported as imputed. This
            // previously read 0.4 alongside a zero imputed-item count — the
            // published figure claimed estimation that had not happened.
            ->and($snapshot->imputed_share)->toBe(0.0)
            ->and($snapshot->isComparable())->toBeFalse();
    });
});
