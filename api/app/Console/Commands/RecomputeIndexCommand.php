<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Basket;
use App\Models\Country;
use App\Models\Location;
use App\Services\Index\IndexCalculator;
use App\Services\Index\IndexStaleness;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recomputes index snapshots.
 *
 * Two modes. Without arguments it drains the stale queue — snapshots marked
 * because an observation beneath them changed. With `--from`/`--to` it builds
 * or rebuilds a date range outright.
 *
 * Draining is the important one: it is what makes a correction to a historical
 * price actually reach the published figures, rather than leaving the original
 * wrong number in place with no indication.
 */
final class RecomputeIndexCommand extends Command
{
    protected $signature = 'qeema:index
                            {--country= : ISO code; defaults to every active country}
                            {--from= : Start date (YYYY-MM-DD) for a full rebuild}
                            {--to= : End date (YYYY-MM-DD) for a full rebuild}
                            {--location= : Restrict a rebuild to one location slug}
                            {--limit=1000 : Maximum stale snapshots to drain in one run}';

    protected $description = 'Recompute stale index snapshots, or rebuild a date range';

    public function handle(IndexCalculator $calculator, IndexStaleness $staleness): int
    {
        return $this->option('from') !== null
            ? $this->rebuild($calculator)
            : $this->drain($calculator, $staleness);
    }

    private function drain(IndexCalculator $calculator, IndexStaleness $staleness): int
    {
        $pending = $staleness->pending((int) $this->option('limit'));

        if ($pending->isEmpty()) {
            $this->info('No stale snapshots.');

            return self::SUCCESS;
        }

        $this->info("Recomputing {$pending->count()} stale snapshot(s).");
        $bar = $this->output->createProgressBar($pending->count());

        foreach ($pending as $snapshot) {
            $calculator->calculate(
                $snapshot->country,
                $snapshot->location,
                $snapshot->basket,
                CarbonImmutable::instance($snapshot->snapshot_date->toDateTime()),
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$staleness->pendingCount()} still pending.");

        return self::SUCCESS;
    }

    private function rebuild(IndexCalculator $calculator): int
    {
        $from = CarbonImmutable::parse((string) $this->option('from'))->startOfDay();
        $to = CarbonImmutable::parse((string) ($this->option('to') ?? $this->option('from')))->startOfDay();

        if ($to->lessThan($from)) {
            $this->error('--to is before --from.');

            return self::FAILURE;
        }

        $countries = Country::query()
            ->where('is_active', true)
            ->when($this->option('country'), fn ($q) => $q->where('code', strtoupper((string) $this->option('country'))))
            ->get();

        if ($countries->isEmpty()) {
            $this->error('No matching active country.');

            return self::FAILURE;
        }

        $computed = 0;

        foreach ($countries as $country) {
            $locations = Location::query()
                ->where('country_id', $country->id)
                ->where('is_active', true)
                ->when($this->option('location'), fn ($q) => $q->where('slug', $this->option('location')))
                ->get();

            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                // The basket in force *on that date*, not today's — a historical
                // figure must be costed against the definition that applied then.
                $basket = $country->basketOn($date) ?? Basket::query()
                    ->where('country_id', $country->id)
                    ->orderByDesc('version')
                    ->first();

                if ($basket === null) {
                    continue;
                }

                foreach ($locations as $location) {
                    $calculator->calculate($country, $location, $basket, $date);
                    $computed++;
                }
            }
        }

        $this->info("Computed {$computed} snapshot(s).");

        return self::SUCCESS;
    }
}
