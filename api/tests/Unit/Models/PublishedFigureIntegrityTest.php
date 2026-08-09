<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AnomalyScore;
use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;

/*
|--------------------------------------------------------------------------
| Integrity of published figures
|--------------------------------------------------------------------------
|
| Everything here guards a promise the platform makes to the people reading its
| numbers: that a basket is weighted coherently, that an estimate is labelled as
| an estimate, and that an unobtainable exchange rate is never invented.
|
*/

describe('basket weighting', function () {
    it('accepts weights that sum to one', function () {
        $basket = Basket::factory()->create();
        BasketItem::factory()->count(4)->weighing(0.25)->create(['basket_id' => $basket->id]);

        expect($basket->hasValidWeights())->toBeTrue();
    });

    it('rejects weights that do not sum to one', function () {
        // A basket summing to 0.8 would understate coverage by 20% forever,
        // silently, so this must be caught at configuration time.
        $basket = Basket::factory()->create();
        BasketItem::factory()->count(4)->weighing(0.2)->create(['basket_id' => $basket->id]);

        expect($basket->weightSum())->toEqualWithDelta(0.8, 1e-9)
            ->and($basket->hasValidWeights())->toBeFalse();
    });

    it('tolerates floating point noise around one', function () {
        $basket = Basket::factory()->create();
        foreach ([0.1, 0.2, 0.3, 0.4] as $w) {
            BasketItem::factory()->weighing($w)->create(['basket_id' => $basket->id]);
        }

        expect($basket->hasValidWeights())->toBeTrue();
    });

    it('costs an item by quantity rather than weight', function () {
        // Conflating the two is the easiest way to publish a wrong number:
        // weight governs coverage, quantity governs cost.
        $item = BasketItem::factory()->ofQuantity(2.5)->weighing(0.1)->make();

        expect($item->contributionAt(40.0))->toBe(100.0);
    });
});

describe('basket versioning', function () {
    it('is in force on a date inside its window', function () {
        $basket = Basket::factory()->create([
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        expect($basket->isEffectiveOn(new DateTimeImmutable('2026-03-15')))->toBeTrue();
    });

    it('is not in force before it starts', function () {
        $basket = Basket::factory()->create(['effective_from' => '2026-01-01']);

        expect($basket->isEffectiveOn(new DateTimeImmutable('2025-12-31')))->toBeFalse();
    });

    it('is not in force after it is superseded', function () {
        $basket = Basket::factory()->create([
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);

        expect($basket->isEffectiveOn(new DateTimeImmutable('2026-07-01')))->toBeFalse();
    });

    it('remains in force indefinitely when it has no end date', function () {
        $basket = Basket::factory()->create([
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        expect($basket->isEffectiveOn(new DateTimeImmutable('2099-01-01')))->toBeTrue();
    });
});

describe('imputation is never disguised', function () {
    it('marks an observed item as not imputed', function () {
        $item = IndexSnapshotItem::factory()->make();

        expect($item->is_imputed)->toBeFalse()
            ->and($item->imputation_method)->toBeNull()
            ->and($item->observation_count)->toBeGreaterThan(0);
    });

    it('marks an estimated item as imputed and names the method', function () {
        $item = IndexSnapshotItem::factory()->imputed()->make();

        expect($item->is_imputed)->toBeTrue()
            ->and($item->imputation_method)->toBe(IndexSnapshotItem::METHOD_MODEL)
            ->and($item->observation_count)->toBe(0);
    });

    it('gives an imputed value a wider interval than an observed one', function () {
        // An imputed value carrying an observed value's narrow interval would
        // misrepresent how much is actually known about it.
        $observed = IndexSnapshotItem::factory()->pricedAt(100.0)->make();
        $imputed = IndexSnapshotItem::factory()->pricedAt(100.0)->imputed()->make();

        expect($imputed->relativeIntervalWidth())
            ->toBeGreaterThan($observed->relativeIntervalWidth());
    });

    it('distinguishes a model estimate from a crude fallback', function () {
        $fallback = IndexSnapshotItem::factory()->imputedByFallback()->make();

        expect($fallback->imputation_method)->toBe(IndexSnapshotItem::METHOD_FALLBACK_ADMIN1);
    });

    it('separates observed from imputed items by scope', function () {
        $snapshot = IndexSnapshot::factory()->create();
        IndexSnapshotItem::factory()->count(3)->create(['index_snapshot_id' => $snapshot->id]);
        IndexSnapshotItem::factory()->count(2)->imputed()->create(['index_snapshot_id' => $snapshot->id]);

        expect(IndexSnapshotItem::query()->observed()->count())->toBe(3)
            ->and(IndexSnapshotItem::query()->imputed()->count())->toBe(2);
    });

    it('reports no interval width when bounds are absent', function () {
        $item = IndexSnapshotItem::factory()->make(['ci_low' => null, 'ci_high' => null]);

        expect($item->relativeIntervalWidth())->toBeNull();
    });
});

describe('snapshot quality reporting', function () {
    it('calls a fully observed snapshot good', function () {
        $snapshot = IndexSnapshot::factory()->make();

        expect($snapshot->qualityLabel())->toBe('good')
            ->and($snapshot->hasLowCoverage())->toBeFalse();
    });

    it('calls a sparsely covered snapshot low', function () {
        $snapshot = IndexSnapshot::factory()->sparse()->make();

        expect($snapshot->hasLowCoverage())->toBeTrue()
            ->and($snapshot->qualityLabel())->toBe('low');
    });

    it('downgrades a snapshot computed against a stale exchange rate', function () {
        $snapshot = IndexSnapshot::factory()->staleFxRate()->make();

        expect($snapshot->qualityLabel())->toBe('moderate');
    });

    it('downgrades a snapshot that leans heavily on imputation', function () {
        $snapshot = IndexSnapshot::factory()->make(['imputed_share' => 0.45]);

        expect($snapshot->qualityLabel())->toBe('moderate');
    });

    it('marks itself stale for recomputation, idempotently', function () {
        $snapshot = IndexSnapshot::factory()->create();

        $snapshot->markStale();
        $snapshot->markStale();

        expect($snapshot->fresh()->is_stale)->toBeTrue()
            ->and(IndexSnapshot::query()->stale()->count())->toBe(1);
    });
});

describe('exchange rates', function () {
    it('prefers the parallel rate by default', function () {
        $rate = FxRate::factory()->withRates(official: 4.8, parallel: 9.6)->make();

        expect($rate->rateFor(FxRate::TYPE_PARALLEL))->toBe(9.6);
    });

    it('uses the official rate when explicitly configured to', function () {
        $rate = FxRate::factory()->withRates(official: 4.8, parallel: 9.6)->make();

        expect($rate->rateFor(FxRate::TYPE_OFFICIAL))->toBe(4.8);
    });

    it('falls back to the other rate when the requested one is missing', function () {
        $rate = FxRate::factory()->withRates(official: 4.8, parallel: null)->make();

        expect($rate->rateFor(FxRate::TYPE_PARALLEL))->toBe(4.8);
    });

    it('returns nothing rather than inventing a rate when both are absent', function () {
        $rate = FxRate::factory()->withRates(official: null, parallel: null)->make();

        expect($rate->rateFor(FxRate::TYPE_PARALLEL))->toBeNull();
    });

    it('computes the parallel premium, itself an indicator of stress', function () {
        $rate = FxRate::factory()->withRates(official: 5.0, parallel: 10.0)->make();

        expect($rate->parallelPremium())->toBe(1.0);
    });

    it('reports no premium when a rate is missing or zero', function () {
        expect(FxRate::factory()->withRates(null, 10.0)->make()->parallelPremium())->toBeNull()
            ->and(FxRate::factory()->withRates(0.0, 10.0)->make()->parallelPremium())->toBeNull();
    });
});

describe('anomaly reporting', function () {
    it('treats a clean verdict as needing no action', function () {
        expect(AnomalyScore::factory()->make()->isActionable())->toBeFalse();
    });

    it('treats suspect and rejected verdicts as actionable', function () {
        expect(AnomalyScore::factory()->suspect()->make()->isActionable())->toBeTrue()
            ->and(AnomalyScore::factory()->rejected()->make()->isActionable())->toBeTrue();
    });

    it('gives a reviewer a readable reason rather than a bare score', function () {
        // A reviewer who cannot see why something was flagged will either
        // rubber-stamp it or ignore it, and both defeat the review queue.
        $score = AnomalyScore::factory()->suspect('Price is 8.2x the local median')->make();

        expect($score->reasonMessages())->toContain('Price is 8.2x the local median');
    });

    it('returns no reasons for a clean submission', function () {
        expect(AnomalyScore::factory()->make()->reasonMessages())->toBe([]);
    });
});
