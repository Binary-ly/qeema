<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\Country;
use App\Models\FxRate;
use App\Models\Location;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;

/**
 * The context the nowcast model sees, for one (location, item, date).
 *
 * One class, used by both the imputer and the trainer, because the alternative
 * is train/serve skew: two assemblies of "the same" features that drift apart,
 * so a model measured at 3.5% error meets something else entirely in
 * production. That failure is silent and looks like the model being bad.
 *
 * **The rule that makes training honest.** No observation of *this* item at
 * *this* location dated on or after `asOf` is ever read. At serving time that
 * costs nothing — the cell is unobserved, which is why it is being imputed. At
 * training time it is the whole game: the target is exactly such an
 * observation, and a feature that has seen it would let the model appear to
 * predict what it was told. Lookahead bias does not announce itself; it shows
 * up as a model that evaluates beautifully and fails in service.
 *
 * Four of these features used to be constants — `nearest_neighbour_km` at 50,
 * `national_trend`, `fx_change_30d` and `location_price_level` at 1.0 — and two
 * more, `national_median` and `neighbour_median`, were computed over identical
 * rows. A model trained on eleven features and served four constants and a
 * duplicate is not the model anybody evaluated.
 */
final class NowcastFeatureBuilder
{
    /** Days of history the spatial features look back over. */
    public const CONTEXT_DAYS = 7;

    /** How many nearby locations count as "the neighbourhood". */
    private const NEIGHBOUR_LIMIT = 5;

    /** Window for judging how expensive a location is in general. */
    private const PRICE_LEVEL_DAYS = 30;

    /** Reported when this location has never reported this item. */
    private const NO_LOCAL_HISTORY_DAYS = 60.0;

    /**
     * @return array<string, float>
     */
    public function build(
        Country $country,
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $asOf,
    ): array {
        $from = $asOf->subDays(self::CONTEXT_DAYS);

        $national = $this->nationalMedian($country, $location, $canonicalItemId, $from, $asOf);
        $neighbourhood = $this->neighbourhood($country, $location, $canonicalItemId, $from, $asOf);
        $last = $this->lastLocalObservation($location, $canonicalItemId, $asOf);

        return [
            'national_median' => $national,
            'neighbour_median' => $neighbourhood['median'],
            'neighbour_weighted' => $neighbourhood['weighted'],
            'neighbour_count' => $neighbourhood['count'],
            'nearest_neighbour_km' => $neighbourhood['nearest_km'],
            'last_local_price' => $last === null
                ? 0.0
                : (float) $last->normalized_price_per_base_unit,
            'days_since_local' => $last === null
                ? self::NO_LOCAL_HISTORY_DAYS
                : (float) CarbonImmutable::parse($last->observed_on->toDateString())->diffInDays($asOf, absolute: true),
            'national_trend' => $this->nationalTrend($country, $location, $canonicalItemId, $asOf),
            'fx_change_30d' => $this->fxChange($country, $asOf),
            'location_price_level' => $this->locationPriceLevel($country, $location, $canonicalItemId, $asOf),
            'day_of_week' => (float) $asOf->dayOfWeek,
        ];
    }

    /**
     * What this item costs elsewhere in the country.
     *
     * Excludes this location entirely. At serving time that changes nothing —
     * the cell is unobserved — and at training time it is what stops the target
     * contributing to the feature that predicts it. It also gives the feature a
     * meaning distinct from the neighbourhood one below, which it did not have
     * while both were computed over every other location.
     */
    private function nationalMedian(
        Country $country,
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $from,
        CarbonImmutable $asOf,
    ): float {
        $median = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('canonical_item_id', $canonicalItemId)
            ->where('location_id', '!=', $location->id)
            ->whereBetween('observed_on', [$from->toDateString(), $asOf->toDateString()])
            ->valid()
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median')
            ->value('median');

        return (float) ($median ?? 0.0);
    }

    /**
     * What the item costs in the nearest places that actually reported.
     *
     * The nearest *reporting* neighbours, not simply the nearest: a location
     * fifteen kilometres away that reported nothing is no help, and treating it
     * as a neighbour would understate how far the evidence really came from.
     *
     * @return array{median: float, weighted: float, count: float, nearest_km: float}
     */
    private function neighbourhood(
        Country $country,
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $from,
        CarbonImmutable $asOf,
    ): array {
        $reported = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('canonical_item_id', $canonicalItemId)
            ->where('location_id', '!=', $location->id)
            ->whereBetween('observed_on', [$from->toDateString(), $asOf->toDateString()])
            ->valid()
            ->selectRaw('location_id, percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median')
            ->groupBy('location_id')
            ->pluck('median', 'location_id');

        if ($reported->isEmpty()) {
            return ['median' => 0.0, 'weighted' => 0.0, 'count' => 0.0, 'nearest_km' => 0.0];
        }

        $candidates = Location::query()
            ->whereIn('id', $reported->keys()->all())
            ->get()
            ->map(fn (Location $other): array => [
                'km' => $location->distanceKmTo($other),
                'price' => (float) $reported[$other->id],
            ])
            ->filter(fn (array $row): bool => $row['km'] !== null)
            ->sortBy('km')
            ->take(self::NEIGHBOUR_LIMIT)
            ->values();

        if ($candidates->isEmpty()) {
            // Locations without coordinates still carry prices; they simply
            // cannot be weighted by distance.
            $prices = $reported->map(fn ($p): float => (float) $p)->values()->all();

            return [
                'median' => $this->median($prices),
                'weighted' => array_sum($prices) / count($prices),
                'count' => (float) count($prices),
                'nearest_km' => 0.0,
            ];
        }

        /** @var list<float> $prices */
        $prices = $candidates->pluck('price')->all();

        return [
            'median' => $this->median($prices),
            'weighted' => $this->inverseDistanceMean($candidates->all()),
            'count' => (float) $candidates->count(),
            'nearest_km' => round((float) $candidates->first()['km'], 3),
        ];
    }

    /**
     * Closer evidence counts for more, but never for everything.
     *
     * The +1 in the denominator keeps a neighbour at zero distance from taking
     * infinite weight, which is not hypothetical: two markets in one city can
     * share coordinates.
     *
     * @param  list<array{km: float|null, price: float}>  $rows
     */
    private function inverseDistanceMean(array $rows): float
    {
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($rows as $row) {
            $weight = 1.0 / (1.0 + (float) $row['km']);
            $weighted += $weight * $row['price'];
            $weights += $weight;
        }

        return $weights > 0.0 ? $weighted / $weights : 0.0;
    }

    /**
     * This location's own last sighting of the item, strictly before the date.
     *
     * Strictly: on the target date the observation *is* the target.
     */
    private function lastLocalObservation(
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $asOf,
    ): ?PriceObservation {
        return PriceObservation::query()
            ->where('location_id', $location->id)
            ->where('canonical_item_id', $canonicalItemId)
            ->where('observed_on', '<', $asOf->toDateString())
            ->valid()
            ->orderByDesc('observed_on')
            ->first();
    }

    /**
     * This week against last week, nationally.
     *
     * 1.0 when either week is missing — a trend nobody can measure is reported
     * as no trend rather than as a number invented to fill the slot.
     */
    private function nationalTrend(
        Country $country,
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $asOf,
    ): float {
        $current = $this->nationalMedian(
            $country,
            $location,
            $canonicalItemId,
            $asOf->subDays(self::CONTEXT_DAYS),
            $asOf,
        );

        $previous = $this->nationalMedian(
            $country,
            $location,
            $canonicalItemId,
            $asOf->subDays(self::CONTEXT_DAYS * 2 + 1),
            $asOf->subDays(self::CONTEXT_DAYS + 1),
        );

        if ($current <= 0.0 || $previous <= 0.0) {
            return 1.0;
        }

        return round($current / $previous, 6);
    }

    /**
     * How far the currency has moved in a month.
     *
     * The single largest driver of price change in the economies this platform
     * serves, and it was pinned at 1.0 — telling the model the currency never
     * moves, in countries chosen because it does.
     */
    private function fxChange(Country $country, CarbonImmutable $asOf): float
    {
        $type = $country->fxRateType();

        $now = $this->rateOn($country, $asOf, $type);
        $before = $this->rateOn($country, $asOf->subDays(30), $type);

        if ($now === null || $before === null || $before <= 0.0) {
            return 1.0;
        }

        return round($now / $before, 6);
    }

    private function rateOn(Country $country, CarbonImmutable $date, string $type): ?float
    {
        $rate = FxRate::query()
            ->where('country_id', $country->id)
            ->where('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->orderByDesc('is_manual')
            ->first();

        return $rate?->rateFor($type);
    }

    /**
     * Whether this location is generally dearer or cheaper than the country.
     *
     * Measured across every *other* item, so the item being imputed cannot
     * inform the feature that helps impute it. Without this the model has no
     * way to know that a remote southern town runs consistently above the
     * national median, and would impute it the national price.
     */
    private function locationPriceLevel(
        Country $country,
        Location $location,
        int $canonicalItemId,
        CarbonImmutable $asOf,
    ): float {
        $from = $asOf->subDays(self::PRICE_LEVEL_DAYS)->toDateString();
        $to = $asOf->toDateString();

        $local = PriceObservation::query()
            ->where('location_id', $location->id)
            ->where('canonical_item_id', '!=', $canonicalItemId)
            ->whereBetween('observed_on', [$from, $to])
            ->valid()
            ->selectRaw('canonical_item_id, percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median')
            ->groupBy('canonical_item_id')
            ->pluck('median', 'canonical_item_id');

        if ($local->isEmpty()) {
            return 1.0;
        }

        $national = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('location_id', '!=', $location->id)
            ->whereIn('canonical_item_id', $local->keys()->all())
            ->whereBetween('observed_on', [$from, $to])
            ->valid()
            ->selectRaw('canonical_item_id, percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median')
            ->groupBy('canonical_item_id')
            ->pluck('median', 'canonical_item_id');

        $ratios = [];

        foreach ($local as $itemId => $localMedian) {
            $nationalMedian = (float) ($national[$itemId] ?? 0.0);

            if ($nationalMedian > 0.0) {
                $ratios[] = (float) $localMedian / $nationalMedian;
            }
        }

        return $ratios === [] ? 1.0 : round($this->median($ratios), 6);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
