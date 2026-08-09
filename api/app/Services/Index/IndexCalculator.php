<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Fx\FxRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Costs a child-weighted basket for one location on one day.
 *
 * This is the number the platform exists to publish, so the design is shaped
 * around not overstating it.
 *
 * **Quantity drives cost, weight drives coverage.** `cost = Σ quantity × price`
 * answers "what does it cost to buy this basket". Coverage is weight-based,
 * because a missing 13%-weight infant formula and a missing 3%-weight notebook
 * are not equally serious, and a count-based figure would say they were.
 *
 * **The interval combines both sources of uncertainty in one draw.** For
 * observed items the observations are resampled; for imputed items the
 * imputation's own interval is sampled. Adding two separately-computed
 * intervals afterwards would understate the total, and an interval built from
 * sampling noise alone would be badly wrong on a snapshot that is 40% imputed.
 */
final class IndexCalculator
{
    public function __construct(
        private readonly PriceEstimator $estimator = new PriceEstimator,
        private readonly FxRateResolver $fx = new FxRateResolver,
        // Optional: without it, missing items simply count against coverage.
        // A deployment that cannot impute publishes a partial basket and says
        // so, rather than publishing nothing.
        private readonly ?ItemImputer $imputer = null,
    ) {}

    public function calculate(
        Country $country,
        Location $location,
        Basket $basket,
        CarbonImmutable $date,
    ): IndexSnapshot {
        $settings = $country->indexSettings();
        $windowDays = (int) $settings['observation_window_days'];
        $halfLife = (float) $settings['recency_half_life_days'];
        $draws = (int) $settings['bootstrap_draws'];

        $items = $basket->items()->with('canonicalItem')->get();
        $observations = $this->observationsFor($location, $items, $date, $windowDays);

        $costLocal = 0.0;
        $observedWeight = 0.0;
        $imputedWeight = 0.0;
        $totalWeight = 0.0;
        $observedCount = 0;

        /** @var list<array<string, mixed>> $itemRows */
        $itemRows = [];
        /** @var list<BasketItem> $missing */
        $missing = [];
        /** @var list<array{quantity: float, samples: list<float>, point: float}> $components */
        $components = [];

        foreach ($items as $entry) {
            $totalWeight += (float) $entry->weight;

            $estimate = $this->estimator->estimate(
                $observations->get($entry->canonical_item_id, collect()),
                $date,
                $halfLife,
            );

            if ($estimate === null) {
                // No observation in the window. Held for imputation below; if
                // that fails the weight counts against coverage rather than the
                // item being quietly dropped, which would make a half-empty
                // basket look complete.
                $missing[] = $entry;

                continue;
            }

            $contribution = (float) $entry->quantity * $estimate->value;
            $costLocal += $contribution;
            $observedWeight += (float) $entry->weight;
            $observedCount++;

            $samples = $this->estimator->bootstrap(
                $estimate,
                $draws,
                // Seeded per item and date so a recomputation of the same
                // snapshot reproduces the same interval exactly.
                $this->seedFor($location->id, $entry->canonical_item_id, $date),
            );

            $components[] = [
                'quantity' => (float) $entry->quantity,
                'samples' => $samples,
                'point' => $estimate->value,
            ];

            $itemRows[] = [
                'canonical_item_id' => $entry->canonical_item_id,
                'unit_price_local' => round($estimate->value, 6),
                'weight' => (float) $entry->weight,
                'quantity' => (float) $entry->quantity,
                'contribution_local' => round($contribution, 4),
                'is_imputed' => false,
                'imputation_method' => null,
                'ci_low' => $samples === [] ? null : round($this->percentile($samples, 2.5), 6),
                'ci_high' => $samples === [] ? null : round($this->percentile($samples, 97.5), 6),
                'observation_count' => $estimate->observationCount,
                'source_observation_ids' => $estimate->observationIds,
            ];
        }

        // Impute what was not observed. This is what makes two locations
        // comparable: without it, `cost_local` prices only the observed part of
        // the basket, and a thinly-covered location reads as *cheaper* than a
        // well-covered one — exactly backwards, since thin coverage usually
        // accompanies harder conditions.
        $imputations = $this->imputer?->impute($country, $location, $missing, $date) ?? [];

        foreach ($missing as $entry) {
            $imputed = $imputations[$entry->canonical_item_id] ?? null;

            if ($imputed === null) {
                // Neither observed nor imputed: this item has no price at all.
                // Its weight is counted in neither share, so
                // `coverage_pct + imputed_share < 1` marks the basket as
                // genuinely incomplete — which is what `isComparable()` reads.
                //
                // Counting it as imputed weight (as this once did) claimed the
                // item had been estimated when nothing had estimated it, and
                // forced the two shares to sum to exactly 1.0 on every
                // snapshot, leaving no way to tell a complete basket from a
                // broken one.
                continue;
            }

            $imputedWeight += (float) $entry->weight;

            $contribution = (float) $entry->quantity * $imputed['value'];
            $costLocal += $contribution;

            $components[] = [
                'quantity' => (float) $entry->quantity,
                // The imputation's own interval is sampled, so the basket
                // interval reflects imputation uncertainty rather than only
                // sampling noise — which would be badly wrong on a snapshot
                // that is largely imputed.
                'samples' => [$imputed['lower'], $imputed['value'], $imputed['upper']],
                'point' => $imputed['value'],
            ];

            $itemRows[] = [
                'canonical_item_id' => $entry->canonical_item_id,
                'unit_price_local' => round($imputed['value'], 6),
                'weight' => (float) $entry->weight,
                'quantity' => (float) $entry->quantity,
                'contribution_local' => round($contribution, 4),
                // Never anything but true on this path.
                'is_imputed' => true,
                'imputation_method' => $imputed['method'],
                'ci_low' => round($imputed['lower'], 6),
                'ci_high' => round($imputed['upper'], 6),
                'observation_count' => 0,
                'source_observation_ids' => [],
            ];
        }

        [$ciLow, $ciHigh] = $this->basketInterval($components, $draws);

        $rate = $this->fx->resolve($country, $date);

        $snapshot = DB::transaction(function () use (
            $country, $location, $basket, $date, $costLocal, $observedWeight,
            $imputedWeight, $totalWeight, $observedCount, $items, $ciLow, $ciHigh, $rate, $itemRows
        ): IndexSnapshot {
            $snapshot = IndexSnapshot::query()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'basket_id' => $basket->id,
                    'snapshot_date' => $date->toDateString(),
                ],
                [
                    'country_id' => $country->id,
                    'cost_local' => round($costLocal, 4),
                    // Null rather than an invented conversion when no usable
                    // rate exists. A missing number is honest; a wrong one is
                    // indistinguishable from a right one.
                    'cost_usd' => $rate === null ? null : round($costLocal / $rate->rate, 4),
                    'coverage_pct' => $totalWeight > 0 ? round($observedWeight / $totalWeight, 4) : 0.0,
                    'imputed_share' => $totalWeight > 0 ? round($imputedWeight / $totalWeight, 4) : 0.0,
                    'ci_low_local' => $ciLow === null ? null : round($ciLow, 4),
                    'ci_high_local' => $ciHigh === null ? null : round($ciHigh, 4),
                    'fx_rate_used' => $rate?->rate,
                    'fx_rate_type' => $rate?->type,
                    'fx_rate_date' => $rate?->rateDate->toDateString(),
                    'fx_is_stale' => $rate === null ? true : $rate->isStale,
                    'observed_item_count' => $observedCount,
                    'total_item_count' => $items->count(),
                    'is_stale' => false,
                    'computed_at' => CarbonImmutable::now(),
                    'model_version' => 'index-'.config('qeema.version'),
                ],
            );

            // Replaced wholesale: a recomputation must not leave behind item
            // rows from a previous run whose observations have since been
            // invalidated.
            $snapshot->items()->delete();

            foreach ($itemRows as $row) {
                $snapshot->items()->create($row);
            }

            return $snapshot;
        });

        return $snapshot->fresh(['items']) ?? $snapshot;
    }

    /**
     * Observations in the window, grouped by item.
     *
     * Converted to a plain collection at the boundary: Eloquent's collection
     * generic requires a Model as its value type, so a collection-of-collections
     * cannot be expressed with it.
     *
     * @param  Collection<int, BasketItem>  $items
     * @return Collection<array-key, EloquentCollection<int, PriceObservation>>
     */
    private function observationsFor(
        Location $location,
        Collection $items,
        CarbonImmutable $date,
        int $windowDays,
    ): Collection {
        return PriceObservation::query()
            ->where('location_id', $location->id)
            ->whereIn('canonical_item_id', $items->pluck('canonical_item_id')->all())
            ->whereBetween('observed_on', [
                $date->subDays($windowDays)->toDateString(),
                $date->toDateString(),
            ])
            ->valid()
            ->get()
            ->groupBy('canonical_item_id')
            ->pipe(fn ($grouped): Collection => collect($grouped->all()));
    }

    /**
     * Monte Carlo interval for the whole basket.
     *
     * Each draw takes one bootstrap sample per item and sums them, so the
     * interval reflects the joint uncertainty rather than the sum of
     * independently-taken bounds — which would be far too wide.
     *
     * @param  list<array{quantity: float, samples: list<float>, point: float}>  $components
     * @return array{0: float|null, 1: float|null}
     */
    private function basketInterval(array $components, int $draws): array
    {
        if ($components === [] || $draws <= 0) {
            return [null, null];
        }

        $totals = [];

        for ($draw = 0; $draw < $draws; $draw++) {
            $total = 0.0;

            foreach ($components as $component) {
                $samples = $component['samples'];
                $value = $samples === []
                    ? $component['point']
                    : $samples[$draw % count($samples)];

                $total += $component['quantity'] * $value;
            }

            $totals[] = $total;
        }

        return [$this->percentile($totals, 2.5), $this->percentile($totals, 97.5)];
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $rank = ($percentile / 100.0) * (count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        if ($low === $high) {
            return $values[$low];
        }

        return $values[$low] + ($rank - $low) * ($values[$high] - $values[$low]);
    }

    /** Deterministic seed so recomputing a snapshot reproduces its interval. */
    private function seedFor(int $locationId, int $itemId, CarbonImmutable $date): int
    {
        return crc32(sprintf('%d:%d:%s', $locationId, $itemId, $date->toDateString()));
    }
}
