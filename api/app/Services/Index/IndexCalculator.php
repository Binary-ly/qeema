<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\BasketLink;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Unit;
use App\Services\Fx\FxRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    /**
     * Anchors already looked up, keyed basket:location.
     *
     * Publishing walks every location across a backfill window, so without this
     * the anchor is re-fetched once per location per day for a value that cannot
     * change during a run.
     *
     * @var array<string, BasketLink|null>
     */
    private array $anchors = [];

    /**
     * Cost the basket without storing anything.
     *
     * Split out from `calculate()` so chain-linking can cost a basket on a date
     * when a *different* basket was in force, which is what establishing a link
     * requires. Persisting that would put a snapshot in the series for a basket
     * that was not in force on the day (D-22).
     */
    public function cost(
        Country $country,
        Location $location,
        Basket $basket,
        CarbonImmutable $date,
    ): BasketCost {
        $settings = $country->indexSettings();
        $windowDays = (int) $settings['observation_window_days'];
        $halfLife = (float) $settings['recency_half_life_days'];
        $draws = (int) $settings['bootstrap_draws'];

        $items = $basket->items()->with('canonicalItem')->get();
        $observations = $this->observationsFor($location, $items, $date, $windowDays);

        // Observations are stored as a price per *base* unit — per kilogram,
        // per litre, per piece. A basket line is a quantity in whatever unit
        // reads naturally to the person who wrote the country file: "60 ml of
        // paracetamol", "400 g of formula". Multiplying those two directly is a
        // dimensional error, and it silently produced a number rather than a
        // complaint: sixty millilitres costed as sixty litres, a thousandfold.
        $factors = Unit::query()
            ->where('country_id', $country->id)
            ->pluck('factor_to_base', 'code')
            ->map(fn ($factor): float => (float) $factor)
            ->all();

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

            $baseQuantity = $this->baseQuantity($entry, $factors);
            $contribution = $baseQuantity * $estimate->value;
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
                'quantity' => $baseQuantity,
                'samples' => $samples,
                'point' => $estimate->value,
            ];

            $itemRows[] = [
                'canonical_item_id' => $entry->canonical_item_id,
                'unit_price_local' => round($estimate->value, 6),
                'weight' => (float) $entry->weight,
                'quantity' => $baseQuantity,
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

            $baseQuantity = $this->baseQuantity($entry, $factors);
            $contribution = $baseQuantity * $imputed['value'];
            $costLocal += $contribution;

            $components[] = [
                'quantity' => $baseQuantity,
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
                'quantity' => $baseQuantity,
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

        return new BasketCost(
            costLocal: round($costLocal, 4),
            coveragePct: $totalWeight > 0 ? round($observedWeight / $totalWeight, 4) : 0.0,
            imputedShare: $totalWeight > 0 ? round($imputedWeight / $totalWeight, 4) : 0.0,
            ciLow: $ciLow === null ? null : round($ciLow, 4),
            ciHigh: $ciHigh === null ? null : round($ciHigh, 4),
            observedItemCount: $observedCount,
            totalItemCount: $items->count(),
            itemRows: $itemRows,
        );
    }

    public function calculate(
        Country $country,
        Location $location,
        Basket $basket,
        CarbonImmutable $date,
    ): IndexSnapshot {
        $cost = $this->cost($country, $location, $basket, $date);

        $rate = $this->fx->resolve($country, $date);

        // Null unless this basket has an anchor at this location. A level
        // computed from a missing anchor would be a number with no reference
        // period behind it, which is worse than no number.
        $level = $this->levelFor($basket, $location, $cost);

        $snapshot = DB::transaction(function () use (
            $country, $location, $basket, $date, $cost, $rate, $level
        ): IndexSnapshot {
            $itemRows = $cost->itemRows;
            $snapshot = IndexSnapshot::query()->updateOrCreate(
                [
                    'location_id' => $location->id,
                    'basket_id' => $basket->id,
                    'snapshot_date' => $date->toDateString(),
                ],
                [
                    'country_id' => $country->id,
                    'cost_local' => $cost->costLocal,
                    // Null rather than an invented conversion when no usable
                    // rate exists. A missing number is honest; a wrong one is
                    // indistinguishable from a right one.
                    'cost_usd' => $rate === null ? null : round($cost->costLocal / $rate->rate, 4),
                    // The chain-linked level. Comparable across a basket
                    // revision, which `cost_local` is not: revise the basket and
                    // the cost steps because the bundle changed, not because
                    // prices did.
                    'index_level' => $level,
                    'coverage_pct' => $cost->coveragePct,
                    'imputed_share' => $cost->imputedShare,
                    'ci_low_local' => $cost->ciLow,
                    'ci_high_local' => $cost->ciHigh,
                    'fx_rate_used' => $rate?->rate,
                    'fx_rate_type' => $rate?->type,
                    'fx_rate_date' => $rate?->rateDate->toDateString(),
                    'fx_is_stale' => $rate === null ? true : $rate->isStale,
                    'observed_item_count' => $cost->observedItemCount,
                    'total_item_count' => $cost->totalItemCount,
                    'is_stale' => false,
                    // Cleared with the flag it belongs to. Leaving the stamp
                    // behind would make a freshly computed snapshot look like
                    // the oldest thing in the backlog.
                    'stale_marked_at' => null,
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
     * The chain-linked level, or null when this basket has no anchor here.
     *
     * Null rather than a fallback. A level is a ratio to a reference period, so
     * without an anchor there is no reference period and therefore no level —
     * inventing one by treating today as the base would produce a series that
     * reads as 100 everywhere and moves for no reason.
     */
    private function levelFor(Basket $basket, Location $location, BasketCost $cost): ?float
    {
        if ($cost->isEmpty()) {
            return null;
        }

        $key = $basket->id.':'.$location->id;

        if (! array_key_exists($key, $this->anchors)) {
            $this->anchors[$key] = BasketLink::anchorFor($basket, $location);
        }

        $anchor = $this->anchors[$key];

        if ($anchor === null || $anchor->reference_cost <= 0.0) {
            return null;
        }

        return round(100.0 * $cost->costLocal / $anchor->reference_cost, 4);
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
    /**
     * A basket line's quantity, expressed in the unit its price is quoted in.
     *
     * `normalized_price_per_base_unit` is exactly what it says, so the quantity
     * multiplying it has to be in base units too. `factor_to_base` is the whole
     * conversion: 0.001 for millilitres against litres, 12 for a dozen against
     * pieces, 1 for the units that happen to be base units already.
     *
     * That last case is why this went unnoticed. Every unit in the shipped
     * baskets except `ml` has a factor of exactly 1, so omitting the
     * multiplication was correct everywhere anyone looked.
     *
     * An unknown unit throws rather than defaulting to 1. A default would be a
     * guess about what somebody meant, and the failure mode of guessing here is
     * a published price that is wrong by orders of magnitude with nothing on
     * the surface to show it.
     *
     * @param  array<string, float>  $factors
     */
    private function baseQuantity(BasketItem $entry, array $factors): float
    {
        $factor = $factors[$entry->unit_code] ?? null;

        if ($factor === null || $factor <= 0.0) {
            throw new RuntimeException(
                "Basket item {$entry->canonical_item_id} is priced in '{$entry->unit_code}', "
                .'which this country does not define as a unit. Costing it would require '
                .'guessing a conversion factor.'
            );
        }

        return (float) $entry->quantity * $factor;
    }

    /**
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
