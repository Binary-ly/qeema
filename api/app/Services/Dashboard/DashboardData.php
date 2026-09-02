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
        // DISTINCT ON: one ordered index walk rather than a grouped subquery
        // joined back against the table. See IndexController::current for the
        // measurement.
        return IndexSnapshot::query()
            // Laravel has no distinctOn() helper; DISTINCT ON is Postgres
            // syntax that must lead the select list, and its expression has to
            // match the leading ORDER BY column exactly.
            ->selectRaw('DISTINCT ON (index_snapshots.location_id) index_snapshots.*')
            ->where('index_snapshots.country_id', $country->id)
            ->with(['location', 'items.canonicalItem'])
            ->orderBy('index_snapshots.location_id')
            // A snapshot that priced nothing is not a current figure; it is the
            // absence of one. The publisher rolls a snapshot forward for every
            // calendar day, so a deployment whose newest observations are weeks
            // old has an unbroken run of empty snapshots on top of real ones —
            // and taking the newest by date alone puts `cost_local` 0.00 on the
            // headline, which reads as a measurement that the basket is free.
            //
            // Preferring the newest snapshot that actually priced something
            // shows the last real figure with its own date beside it, which is
            // what a reader means by "current". Consumers of the API get the
            // unfiltered series and the `coverage` field to judge it by; this
            // is a presentation decision and deliberately not a contract one.
            ->orderByRaw('(index_snapshots.coverage_pct > 0) DESC')
            ->orderByDesc('index_snapshots.snapshot_date')
            ->get();
    }

    /**
     * Map points and the country outline behind them, ready to draw.
     *
     * @param  Collection<int, IndexSnapshot>  $snapshots
     * @return array{projection: MapProjection, points: list<array<string, mixed>>, outline: list<string>}
     */
    public function mapPoints(Country $country, Collection $snapshots, float $width = 800.0): array
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

        $outline = CountryOutline::forCountry($country->code);

        // Fitted to the outline as well as the towns, or the country would be
        // scaled to the bounding box of wherever people happen to report and
        // most of it would fall outside the frame.
        $fitTo = array_merge($outline->vertices(), $coords);

        // The frame follows the country's own proportions instead of a fixed
        // 800x520. A country whose projected aspect is near square was being
        // drawn into a 3:2 box, so it occupied about 460px of 800 and the rest
        // was empty — which read as a broken map rather than a narrow one.
        $height = self::frameHeight($fitTo, $width);

        $projection = MapProjection::fit($fitTo, $width, $height);

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

        return [
            'projection' => $projection,
            'points' => self::placeLabels($points),
            'outline' => $outline->paths($projection),
        ];
    }

    /**
     * The basket, item by item, and how much of it anyone can actually price.
     *
     * This is the thing the platform is about, and until now it was the one
     * thing the dashboard never showed. A reader could see a cost without ever
     * seeing what was being costed — and the composition is a judgement, so
     * publishing the total while hiding the list asks to be trusted rather than
     * checked.
     *
     * It also states the gap plainly. Where an item has no price in any
     * location, that is not a rendering gap: it is a category of thing a child
     * needs that no source in this deployment tracks. Those rows are the
     * argument for the crowdsourced layer, and they should be visible.
     *
     * @param  Collection<int, IndexSnapshot>  $snapshots
     * @return list<array<string, mixed>>
     */
    public function basketCoverage(Country $country, Collection $snapshots): array
    {
        $basket = $country->basketOn(CarbonImmutable::now())
            ?? $country->baskets()->orderByDesc('version')->first();

        if ($basket === null) {
            return [];
        }

        // How many locations carry a price for each item. Counted from the
        // published snapshots rather than from observations directly, so this
        // says what the index actually used.
        $priced = [];
        $imputed = [];

        foreach ($snapshots as $snapshot) {
            // `unit_price_local` is not nullable, and the calculator only
            // writes a row for an item it could price — so the row existing is
            // itself the signal that the item has a price here.
            foreach ($snapshot->items as $item) {
                $id = (int) $item->canonical_item_id;
                $priced[$id] = ($priced[$id] ?? 0) + 1;

                if ($item->is_imputed) {
                    $imputed[$id] = ($imputed[$id] ?? 0) + 1;
                }
            }
        }

        $locations = $snapshots->count();
        $rows = [];

        foreach ($basket->items()->with('canonicalItem')->get() as $entry) {
            $item = $entry->canonicalItem;

            if ($item === null) {
                continue;
            }

            $id = (int) $entry->canonical_item_id;

            $rows[] = [
                'code' => $item->code,
                'name' => $item->name_en,
                'name_local' => $item->name_local,
                'category' => $entry->category,
                'weight' => (float) $entry->weight,
                'locations' => $priced[$id] ?? 0,
                'imputed' => $imputed[$id] ?? 0,
                'total_locations' => $locations,
            ];
        }

        // Heaviest first: the weight is how much of a household's spend the
        // item represents, so an unpriced item at the top of this list costs
        // the index far more than an unpriced one at the bottom.
        usort($rows, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return $rows;
    }

    /**
     * Decide where each point's label goes, so they do not overlap.
     *
     * Reporting clusters on the coast, which is exactly where a naive "label
     * above the dot" rule fails: four towns within forty kilometres produced
     * four labels stacked on the same pixels and none of them readable.
     *
     * A greedy pass, top to bottom: try above, then below, and if both are
     * taken leave the label off. Dropping one is the right failure — every
     * location is named in full in the table below, and the map's job is the
     * spatial pattern rather than the roll call.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private static function placeLabels(array $points): array
    {
        // Top to bottom, so the northern label of a pair keeps the position
        // above and the southern one moves below it rather than the reverse.
        usort($points, static fn (array $a, array $b): int => $a['y'] <=> $b['y']);

        /** @var list<array{x1: float, y1: float, x2: float, y2: float}> $taken */
        $taken = [];

        foreach ($points as $index => $point) {
            // Approximate: 6.2px per character at 11px, which is close enough
            // for a collision test and needs no font metrics.
            $halfWidth = max(strlen((string) $point['name']) * 6.2 / 2, 12.0);

            $placed = false;

            foreach ([-14.0, 20.0] as $dy) {
                $box = [
                    'x1' => $point['x'] - $halfWidth,
                    'x2' => $point['x'] + $halfWidth,
                    'y1' => $point['y'] + $dy - 9.0,
                    'y2' => $point['y'] + $dy + 3.0,
                ];

                $collides = false;

                foreach ($taken as $other) {
                    if ($box['x1'] < $other['x2'] && $box['x2'] > $other['x1']
                        && $box['y1'] < $other['y2'] && $box['y2'] > $other['y1']) {
                        $collides = true;
                        break;
                    }
                }

                if (! $collides) {
                    $taken[] = $box;
                    $points[$index]['label_dy'] = $dy;
                    $points[$index]['label_show'] = true;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $points[$index]['label_dy'] = -14.0;
                $points[$index]['label_show'] = false;
            }
        }

        return $points;
    }

    /**
     * A frame with the country's own proportions.
     *
     * @param  list<array{latitude: float, longitude: float}>  $vertices
     */
    private static function frameHeight(array $vertices, float $width, float $padding = 40.0): float
    {
        if ($vertices === []) {
            return 520.0;
        }

        $lats = array_column($vertices, 'latitude');
        $lons = array_column($vertices, 'longitude');

        $meanLat = (min($lats) + max($lats)) / 2.0;
        $spanX = max((max($lons) - min($lons)) * cos(deg2rad($meanLat)), 1e-9);
        $spanY = max(max($lats) - min($lats), 1e-9);

        $height = ($width - 2 * $padding) * ($spanY / $spanX) + 2 * $padding;

        // Bounded so a very tall or very wide country still produces a frame
        // that fits on a phone without becoming a letterbox.
        //
        // The upper bound is 560 rather than 760 because reporting clusters
        // where people live. A country that projects nearly square, drawn to
        // its true proportions, left most of the frame empty with four towns in
        // it — geographically honest, and a great deal of page for very little
        // information.
        return max(300.0, min(560.0, $height));
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
            // The date of the data on the page, which is the newest date that
            // priced anything — not the newest date present.
            //
            // Taking the plain maximum dated the whole page today while every
            // row under it read "103 days ago". The publisher rolls a snapshot
            // forward for every calendar day, so a location that has never
            // been priced contributes an empty snapshot dated today, and one
            // such location was enough to stamp a four-month-old median with
            // this morning's date. That is the exact failure this repository
            // treats as unacceptable: an old measurement presented as a fresh
            // one.
            'as_of' => $snapshots
                ->filter(static fn (IndexSnapshot $s): bool => (float) $s->coverage_pct > 0)
                ->max('snapshot_date')?->toDateString(),
        ];
    }
}
