<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembles everything the public dashboard renders.
 *
 * Kept out of the controller because the shaping decisions here are the
 * substance of the page, and they carry the same honesty rules the API enforces:
 *
 * - A location whose basket is not fully priced is **not comparable**, and is
 *   never ranked against one that is. Phase 7 found this the hard way: a
 *   thinly-covered location read as *cheaper*, because the part of the basket
 *   nobody had priced cost nothing. Sorting a map legend by that number would
 *   publish the same lie in colour.
 * - The imputed share travels with every figure, so "estimated" is visible in
 *   the interface rather than buried in the API.
 * - A missing exchange rate produces a null USD figure and a stated reason,
 *   never a silent fallback to some other rate.
 */
final readonly class DashboardData
{
    /**
     * The latest snapshot for each location in a country.
     *
     * Latest *per location* rather than "everything from the most recent date":
     * a location that has not reported today should still show its last known
     * figure, marked with its age, instead of vanishing from the map.
     *
     * @return Collection<int, IndexSnapshot>
     */
    public function currentSnapshots(Country $country): Collection
    {
        $latest = DB::table('index_snapshots')
            ->selectRaw('location_id, MAX(snapshot_date) AS snapshot_date')
            ->where('country_id', $country->id)
            ->groupBy('location_id');

        return IndexSnapshot::query()
            ->joinSub($latest, 'latest', function ($join): void {
                $join->on('index_snapshots.location_id', '=', 'latest.location_id')
                    ->on('index_snapshots.snapshot_date', '=', 'latest.snapshot_date');
            })
            ->where('index_snapshots.country_id', $country->id)
            ->with(['location', 'items.canonicalItem'])
            ->select('index_snapshots.*')
            ->orderBy('index_snapshots.location_id')
            ->get();
    }

    /**
     * Map points, ready to draw.
     *
     * @param  Collection<int, IndexSnapshot>  $snapshots
     * @return array{projection: MapProjection, points: list<array<string, mixed>>}
     */
    public function mapPoints(Collection $snapshots, float $width = 800.0, float $height = 520.0): array
    {
        /** @var list<array{latitude: float, longitude: float}> $coords */
        $coords = $snapshots
            ->map(static fn (IndexSnapshot $s): ?array => $s->location === null ? null : [
                'latitude' => (float) $s->location->latitude,
                'longitude' => (float) $s->location->longitude,
            ])
            ->filter()
            ->values()
            ->all();

        $projection = MapProjection::fit($coords, $width, $height);

        // Only comparable locations get a colour scale position. An incomparable
        // one is drawn hollow — visibly present, deliberately unranked.
        $comparableCosts = $snapshots
            ->filter(static fn (IndexSnapshot $s): bool => $s->isComparable())
            ->map(static fn (IndexSnapshot $s): float => (float) $s->cost_local)
            ->filter(static fn (float $c): bool => $c > 0)
            ->values();

        $min = $comparableCosts->min() ?? 0.0;
        $max = $comparableCosts->max() ?? 0.0;
        $span = max($max - $min, 1e-9);

        $points = [];

        foreach ($snapshots as $snapshot) {
            $location = $snapshot->location;

            if ($location === null) {
                continue;
            }

            $xy = $projection->project((float) $location->latitude, (float) $location->longitude);
            $comparable = $snapshot->isComparable();
            $cost = (float) $snapshot->cost_local;

            $points[] = [
                'slug' => $location->slug,
                'name' => $location->name,
                'name_local' => $location->name_local,
                'x' => $xy['x'],
                'y' => $xy['y'],
                'cost' => $cost,
                'comparable' => $comparable,
                // Null rather than 0 when incomparable: there is no honest
                // position on the scale for a partially-priced basket.
                'intensity' => $comparable && $cost > 0 ? round(($cost - $min) / $span, 4) : null,
                'coverage' => (float) $snapshot->coverage_pct,
                'imputed_share' => (float) $snapshot->imputed_share,
                'quality' => $snapshot->qualityLabel(),
                'date' => $snapshot->snapshot_date->toDateString(),
                'days_old' => (int) CarbonImmutable::parse($snapshot->snapshot_date->toDateString())
                    ->diffInDays(CarbonImmutable::now()->startOfDay()),
            ];
        }

        return ['projection' => $projection, 'points' => $points];
    }

    /**
     * National cost over time, for the headline chart.
     *
     * The series is the median across *comparable* locations only. Including
     * partially-priced baskets would drag the national figure downward on
     * exactly the days when coverage was worst — producing an apparent
     * improvement that is really a reporting gap.
     *
     * @return list<array{date: string, cost: float, locations: int}>
     */
    public function nationalSeries(Country $country, int $days = 90): array
    {
        $since = CarbonImmutable::now()->subDays($days)->toDateString();

        $rows = DB::table('index_snapshots')
            ->selectRaw('snapshot_date, COUNT(*) AS locations, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY cost_local) AS median_cost')
            ->where('country_id', $country->id)
            ->where('snapshot_date', '>=', $since)
            ->where('coverage_pct', '>=', 1.0)
            ->groupBy('snapshot_date')
            ->orderBy('snapshot_date')
            ->get();

        return $rows->map(static fn (object $row): array => [
            'date' => (string) $row->snapshot_date,
            'cost' => round((float) $row->median_cost, 2),
            'locations' => (int) $row->locations,
        ])->all();
    }

    /**
     * Per-location series for the comparison chart.
     *
     * @return list<array{slug: string, name: string, points: list<array{date: string, cost: float}>}>
     */
    public function locationSeries(Country $country, int $days = 90, int $limit = 6): array
    {
        $since = CarbonImmutable::now()->subDays($days)->toDateString();

        $locations = Location::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->orderByDesc('population_estimate')
            ->limit($limit)
            ->get();

        $series = [];

        foreach ($locations as $location) {
            $points = IndexSnapshot::query()
                ->where('location_id', $location->id)
                ->where('snapshot_date', '>=', $since)
                ->where('coverage_pct', '>=', 1.0)
                ->orderBy('snapshot_date')
                ->get(['snapshot_date', 'cost_local'])
                ->map(static fn (IndexSnapshot $s): array => [
                    'date' => $s->snapshot_date->toDateString(),
                    'cost' => round((float) $s->cost_local, 2),
                ])
                ->all();

            if ($points === []) {
                continue;
            }

            $series[] = [
                'slug' => $location->slug,
                'name' => $location->name,
                'points' => $points,
            ];
        }

        return $series;
    }

    /**
     * Official and parallel rates, with the premium between them.
     *
     * The gap is published because it is itself a headline indicator: a widening
     * parallel premium is often the earliest visible sign of the stress this
     * platform exists to measure.
     *
     * @return list<array{date: string, official: ?float, parallel: ?float, premium: ?float}>
     */
    public function fxSeries(Country $country, int $days = 90): array
    {
        $since = CarbonImmutable::now()->subDays($days)->toDateString();

        $rates = FxRate::query()
            ->where('country_id', $country->id)
            ->where('rate_date', '>=', $since)
            ->orderBy('rate_date')
            ->get();

        $series = [];

        foreach ($rates as $rate) {
            $official = $rate->official_rate;
            $parallel = $rate->parallel_rate;

            $series[] = [
                'date' => $rate->rate_date->toDateString(),
                'official' => $official,
                'parallel' => $parallel,
                // Null when either side is missing. A premium computed against
                // an absent official rate would be a fabricated number.
                'premium' => $official !== null && $parallel !== null && $official > 0.0
                    ? round(($parallel - $official) / $official, 4)
                    : null,
            ];
        }

        return $series;
    }

    /**
     * The headline figures, with the caveats that make them readable.
     *
     * @param  Collection<int, IndexSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    public function headline(Country $country, Collection $snapshots): array
    {
        $comparable = $snapshots->filter(static fn (IndexSnapshot $s): bool => $s->isComparable());

        $costs = $comparable->map(static fn (IndexSnapshot $s): float => (float) $s->cost_local)
            ->filter(static fn (float $c): bool => $c > 0)
            ->sort()
            ->values();

        $median = $costs->isEmpty() ? null : (float) $costs->get((int) floor($costs->count() / 2));

        $usdCosts = $comparable
            ->map(static fn (IndexSnapshot $s): ?float => $s->cost_usd === null ? null : (float) $s->cost_usd)
            ->filter()
            ->sort()
            ->values();

        $medianUsd = $usdCosts->isEmpty() ? null : (float) $usdCosts->get((int) floor($usdCosts->count() / 2));

        $cheapest = $comparable->sortBy('cost_local')->first();
        $dearest = $comparable->sortByDesc('cost_local')->first();

        return [
            'currency' => $country->currency_code,
            'median_cost' => $median === null ? null : round($median, 2),
            'median_cost_usd' => $medianUsd === null ? null : round($medianUsd, 2),
            'locations_total' => $snapshots->count(),
            'locations_comparable' => $comparable->count(),
            // Surfaced rather than hidden: if most of the map is incomparable,
            // the headline median is drawn from a handful of places and the
            // reader needs to know that before quoting it.
            'incomparable' => $snapshots->count() - $comparable->count(),
            'mean_coverage' => $snapshots->isEmpty()
                ? 0.0
                : round((float) $snapshots->avg('coverage_pct'), 4),
            'mean_imputed_share' => $snapshots->isEmpty()
                ? 0.0
                : round((float) $snapshots->avg('imputed_share'), 4),
            'cheapest' => $cheapest?->location?->name,
            'dearest' => $dearest?->location?->name,
            'spread' => $cheapest !== null && $dearest !== null && (float) $cheapest->cost_local > 0
                ? round(((float) $dearest->cost_local - (float) $cheapest->cost_local) / (float) $cheapest->cost_local, 4)
                : null,
            'as_of' => $snapshots->max('snapshot_date')?->toDateString(),
        ];
    }
}
