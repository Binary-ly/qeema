<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\Basket;
use App\Models\BasketLink;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives a basket version's level series a reference point.
 *
 * Revising a basket changes what is being priced. `cost_local` therefore steps
 * at the revision for a reason that has nothing to do with prices, and anyone
 * plotting it reads that step as inflation. Chain-linking removes the step
 * without pretending the revision did not happen.
 *
 * The construction gives every version a per-location reference cost, after
 * which the level is uniform across versions and nothing downstream needs to
 * know a link ever occurred:
 *
 *     level(L, t) = 100 × cost_v(L, t) / reference_cost_v(L)
 *
 * The first version is anchored at the country's base date, so its level there
 * is exactly 100. Where a country configures no base date, the base period is
 * instead the first day the basket could be priced in full — a fact about the
 * data rather than an assertion about it — and that date is recorded on the
 * anchor, so it is fixed from then on. Each later version is carried forward by
 * costing **both** baskets on the same day — the last day the old one was in
 * force — and multiplying the old anchor by the ratio:
 *
 *     link_factor(L) = cost_new(L, d) / cost_old(L, d)
 *     reference_new(L) = reference_old(L) × link_factor(L)
 *
 * which makes the level continuous at `d` by construction.
 *
 * Two rules keep this honest. Nothing is anchored on a basket that could not be
 * fully priced (D-20) — a coverage artefact folded into a reference cost never
 * washes out, it shifts every level derived from that anchor for the life of the
 * version. And an anchor, once written, is not rewritten (D-21): recomputing it
 * from data that has arrived since would silently restate every figure already
 * published behind it.
 */
final class ChainLinker
{
    /**
     * How far forward to look for a first fully-priced day when no base date is
     * configured. Coverage builds up over the first days of a series; beyond
     * this the location is not producing a complete basket and should be
     * reported rather than searched for indefinitely.
     */
    private const BASE_PERIOD_SEARCH_DAYS = 45;

    public function __construct(
        private readonly IndexCalculator $calculator = new IndexCalculator,
    ) {}

    public function establish(Country $country, Basket $basket, bool $force = false): LinkReport
    {
        $previous = $this->previousBasket($country, $basket);

        return $previous === null
            ? $this->anchorAtBasePeriod($country, $basket, $force)
            : $this->chainFrom($country, $basket, $previous, $force);
    }

    /**
     * The version immediately before this one.
     *
     * By version rather than by date: `effective_to` is allowed to be null on a
     * basket that is still current, so ordering on it would not identify a
     * predecessor reliably.
     */
    public function previousBasket(Country $country, Basket $basket): ?Basket
    {
        return $country->baskets()
            ->where('version', '<', $basket->version)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * First version: the base period is the reference point, so the level there
     * is 100 and everything else is measured against it.
     */
    private function anchorAtBasePeriod(Country $country, Basket $basket, bool $force): LinkReport
    {
        $baseDate = $country->indexSettings()['base_date'];
        $configured = $baseDate !== null;
        $report = new LinkReport(BasketLink::METHOD_BASE_PERIOD);

        foreach ($this->locations($country) as $location) {
            if ($this->alreadyAnchored($basket, $location, $force, $report)) {
                continue;
            }

            // A configured base date is an assertion by the operator about when
            // their series starts, so it is honoured exactly and fails loudly if
            // there is no data there — quietly anchoring somewhere else would
            // publish a series whose 100 is not the date they documented.
            //
            // With none configured, the base period is the first day the basket
            // can be completely priced. That is a property of the data rather
            // than a wish, and it is recorded on the anchor, so it is fixed from
            // then on.
            $anchorDate = $configured
                ? CarbonImmutable::parse((string) $baseDate)
                : $this->firstCompletelyPricedDate($country, $location, $basket);

            if ($anchorDate === null) {
                $report->skip($location->name, 'no day in the series prices the whole basket');

                continue;
            }

            $cost = $this->calculator->cost($country, $location, $basket, $anchorDate);

            if (! $cost->isComplete()) {
                $report->skip($location->name, sprintf(
                    'basket only %.0f%% priced on %s',
                    ($cost->coveragePct + $cost->imputedShare) * 100,
                    $anchorDate->toDateString(),
                ));

                continue;
            }

            $this->write($country, $basket, $location, [
                'previous_basket_id' => null,
                'link_date' => $anchorDate->toDateString(),
                'reference_cost' => $cost->costLocal,
                'link_factor' => null,
                'method' => BasketLink::METHOD_BASE_PERIOD,
                'previous_cost' => null,
                'linked_cost' => $cost->costLocal,
                'notes' => $configured
                    ? null
                    : 'No base date configured; anchored at the first day the basket was completely priced.',
            ]);

            $report->anchored($location->name);
        }

        return $report;
    }

    /**
     * The first day this basket can be priced in full at this location.
     *
     * Walks forward from the earliest observation rather than scanning the whole
     * series: the answer is almost always within the first few days, and the
     * search is bounded so a location that never achieves full coverage costs a
     * fixed amount of work instead of one query per day of history.
     */
    private function firstCompletelyPricedDate(
        Country $country,
        Location $location,
        Basket $basket,
    ): ?CarbonImmutable {
        $earliest = PriceObservation::query()
            ->where('location_id', $location->id)
            ->valid()
            ->min('observed_on');

        if ($earliest === null) {
            return null;
        }

        $date = CarbonImmutable::parse((string) $earliest);

        for ($offset = 0; $offset <= self::BASE_PERIOD_SEARCH_DAYS; $offset++) {
            $candidate = $date->addDays($offset);

            if ($candidate->isAfter(CarbonImmutable::today())) {
                return null;
            }

            if ($this->calculator->cost($country, $location, $basket, $candidate)->isComplete()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Later version: carried forward from its predecessor's anchor.
     *
     * Both baskets are costed on the last day the old one was in force. That day
     * is the only place the two are directly comparable — the same prices, the
     * same reporters, the same conditions — so the ratio between them isolates
     * the effect of the revision itself.
     */
    private function chainFrom(Country $country, Basket $basket, Basket $previous, bool $force): LinkReport
    {
        $linkDate = CarbonImmutable::parse($basket->effective_from->toDateString())->subDay();

        /** @var array<int, array{location: Location, factor: float, previous: float, linked: float, anchor: BasketLink}> $measured */
        $measured = [];
        /** @var array<int, array{location: Location, anchor: BasketLink|null, reason: string}> $unmeasured */
        $unmeasured = [];

        foreach ($this->locations($country) as $location) {
            $anchor = BasketLink::anchorFor($previous, $location);

            if ($anchor === null) {
                $unmeasured[$location->id] = [
                    'location' => $location,
                    'anchor' => null,
                    'reason' => sprintf('basket v%d has no anchor here to chain from', $previous->version),
                ];

                continue;
            }

            $previousCost = $this->calculator->cost($country, $location, $previous, $linkDate);
            $linkedCost = $this->calculator->cost($country, $location, $basket, $linkDate);

            if (! $previousCost->isComplete() || ! $linkedCost->isComplete() || $previousCost->costLocal <= 0.0) {
                $unmeasured[$location->id] = [
                    'location' => $location,
                    'anchor' => $anchor,
                    'reason' => 'both baskets could not be fully priced on the link date',
                ];

                continue;
            }

            $measured[$location->id] = [
                'location' => $location,
                'factor' => $linkedCost->costLocal / $previousCost->costLocal,
                'previous' => $previousCost->costLocal,
                'linked' => $linkedCost->costLocal,
                'anchor' => $anchor,
            ];
        }

        $countryFactor = $this->median(array_column($measured, 'factor'));
        $report = new LinkReport(BasketLink::METHOD_CHAINED, $countryFactor);

        foreach ($measured as $row) {
            if ($this->alreadyAnchored($basket, $row['location'], $force, $report)) {
                continue;
            }

            $this->write($country, $basket, $row['location'], [
                'previous_basket_id' => $previous->id,
                'link_date' => $linkDate->toDateString(),
                'reference_cost' => round($row['anchor']->reference_cost * $row['factor'], 4),
                'link_factor' => round($row['factor'], 6),
                'method' => BasketLink::METHOD_CHAINED,
                'previous_cost' => $row['previous'],
                'linked_cost' => $row['linked'],
            ]);

            $report->anchored($row['location']->name);
        }

        // A location that could not measure its own factor borrows the
        // country-wide median rather than dropping out of the series. Recorded
        // as a fallback so a level resting on a borrowed factor is never
        // mistaken for one measured where it is published.
        foreach ($unmeasured as $row) {
            if ($row['anchor'] === null || $countryFactor === null) {
                $report->skip($row['location']->name, $row['reason']);

                continue;
            }

            if ($this->alreadyAnchored($basket, $row['location'], $force, $report)) {
                continue;
            }

            $this->write($country, $basket, $row['location'], [
                'previous_basket_id' => $previous->id,
                'link_date' => $linkDate->toDateString(),
                'reference_cost' => round($row['anchor']->reference_cost * $countryFactor, 4),
                'link_factor' => round($countryFactor, 6),
                'method' => BasketLink::METHOD_CHAINED_COUNTRY_FALLBACK,
                'previous_cost' => null,
                'linked_cost' => null,
                'notes' => $row['reason'].'; used the country median factor',
            ]);

            $report->anchored($row['location']->name, viaFallback: true);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(Country $country, Basket $basket, Location $location, array $attributes): void
    {
        DB::transaction(function () use ($country, $basket, $location, $attributes): void {
            BasketLink::query()->updateOrCreate(
                ['basket_id' => $basket->id, 'location_id' => $location->id],
                array_merge($attributes, [
                    'country_id' => $country->id,
                    'computed_at' => CarbonImmutable::now(),
                ]),
            );

            $this->markForRecomputation($basket, $location);
        });
    }

    /**
     * Snapshots published before this anchor existed carry no level.
     *
     * Without this, anchoring is invisible: the publisher will not recompute a
     * date it has already published, and the staleness path only fires when new
     * observations arrive — so an operator runs the link command, sees the API
     * still returning null, and reasonably concludes it did nothing.
     *
     * Marking them stale hands them to the recompute task that already runs
     * every minute, which is the same route corrections take. Only snapshots
     * with no level are touched: one that already has a level was computed
     * against this anchor, and re-deriving it would restate a published figure
     * that was correct.
     */
    private function markForRecomputation(Basket $basket, Location $location): void
    {
        IndexSnapshot::query()
            ->where('basket_id', $basket->id)
            ->where('location_id', $location->id)
            ->whereNull('index_level')
            ->where('is_stale', false)
            ->update([
                'is_stale' => true,
                'stale_marked_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * An existing anchor is left alone unless overwriting was asked for
     * explicitly (D-21).
     */
    private function alreadyAnchored(Basket $basket, Location $location, bool $force, LinkReport $report): bool
    {
        if ($force || BasketLink::anchorFor($basket, $location) === null) {
            return false;
        }

        $report->skip($location->name, 'already anchored; --force to replace');

        return true;
    }

    /**
     * @return Collection<int, Location>
     */
    private function locations(Country $country): Collection
    {
        return $country->locations()->where('is_active', true)->orderBy('id')->get()->collect();
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }
}
