<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Basket;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Services\Index\IndexCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rolls the published index forward, so new dates appear without anyone asking.
 *
 * `qeema:index` only recomputes snapshots that already exist. That is correct
 * for corrections — an observation marks its snapshots stale and the drain
 * republishes them — but it means a deployment left alone would never publish a
 * new calendar day at all. This command is the other half: it creates the
 * snapshots that do not exist yet.
 *
 * **There is no such thing as "today" on this platform.** Two deployments can
 * sit half a day apart, and a server-local date would publish tomorrow's
 * snapshot early in the country to the east and yesterday's late in the country
 * to the west. Every date here is therefore computed in the country's own
 * timezone, read from configuration like every other country fact. Constraint
 * C3 is not only about literals in code; a hardcoded notion of *when* is the
 * same mistake in a different dimension — and naming a real timezone even in a
 * comment is how the first one gets in.
 *
 * Existing snapshots are left alone unless `--force` is given. Recomputing them
 * would duplicate the drain's work and, worse, would republish a figure inside
 * the window the drain deliberately leaves for anomaly screening.
 */
final class PublishIndexCommand extends Command
{
    protected $signature = 'qeema:index:publish
                            {--country= : ISO code; defaults to every active country}
                            {--days= : How many days back to look for missing snapshots}
                            {--force : Recompute snapshots that already exist}';

    protected $description = 'Create index snapshots for dates that have none, up to today in each country';

    public function handle(IndexCalculator $calculator): int
    {
        $backfillDays = (int) ($this->option('days') ?? config('qeema.index.backfill_days'));

        $countries = Country::query()
            ->where('is_active', true)
            ->when(
                $this->option('country'),
                fn ($query) => $query->where('code', strtoupper((string) $this->option('country'))),
            )
            ->get();

        if ($countries->isEmpty()) {
            // An explicit `--country` that matches nothing is an operator
            // mistake worth failing on. An unrestricted run with no active
            // countries is an ordinary empty state — a deployment mid-setup —
            // and failing every hour would train whoever reads the logs to
            // ignore them.
            if ($this->option('country') !== null) {
                $this->error('No matching active country.');

                return self::FAILURE;
            }

            $this->info('No active countries; nothing to publish.');

            return self::SUCCESS;
        }

        $published = 0;

        foreach ($countries as $country) {
            $published += $this->publishFor($country, $calculator, $backfillDays);
        }

        $this->info("Published {$published} snapshot(s).");

        return self::SUCCESS;
    }

    private function publishFor(Country $country, IndexCalculator $calculator, int $backfillDays): int
    {
        $locations = Location::query()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->get();

        if ($locations->isEmpty()) {
            return 0;
        }

        $today = $this->todayIn($country);
        $published = 0;

        for ($date = $today->subDays($backfillDays); $date->lessThanOrEqualTo($today); $date = $date->addDay()) {
            // The basket in force on that date, not today's: a figure for last
            // Tuesday must be costed against the definition that applied then.
            $basket = $country->basketOn($date) ?? Basket::query()
                ->where('country_id', $country->id)
                ->orderByDesc('version')
                ->first();

            if ($basket === null) {
                continue;
            }

            foreach ($locations as $location) {
                if (! $this->option('force') && $this->alreadyPublished($location->id, $basket->id, $date)) {
                    continue;
                }

                $calculator->calculate($country, $location, $basket, $date);
                $published++;
            }
        }

        return $published;
    }

    /**
     * The current calendar date in a country, as a canonical instant.
     *
     * Which day it is comes from the country's timezone; the instant is then
     * normalised to midnight UTC so that recomputing the same snapshot produces
     * identical recency weights regardless of where the process is running.
     */
    private function todayIn(Country $country): CarbonImmutable
    {
        $localDate = CarbonImmutable::now($country->timezone)->toDateString();

        return CarbonImmutable::parse($localDate)->startOfDay();
    }

    private function alreadyPublished(int $locationId, int $basketId, CarbonImmutable $date): bool
    {
        return IndexSnapshot::query()
            ->where('location_id', $locationId)
            ->where('basket_id', $basketId)
            ->whereDate('snapshot_date', $date->toDateString())
            ->exists();
    }
}
