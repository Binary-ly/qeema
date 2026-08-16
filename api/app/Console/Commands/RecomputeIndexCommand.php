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
use Illuminate\Support\Facades\Log;
use Throwable;

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
                            {--limit= : Maximum stale snapshots to drain in one run}
                            {--grace= : Seconds a snapshot must have been stale before it is recomputed}';

    protected $description = 'Recompute stale index snapshots, or rebuild a date range';

    public function handle(IndexCalculator $calculator, IndexStaleness $staleness): int
    {
        return $this->option('from') !== null
            ? $this->rebuild($calculator)
            : $this->drain($calculator, $staleness);
    }

    private function drain(IndexCalculator $calculator, IndexStaleness $staleness): int
    {
        $limit = (int) ($this->option('limit') ?? config('qeema.index.drain_limit'));

        // Defaults from configuration rather than the signature so the
        // scheduled run and an operator's manual run behave identically without
        // the schedule having to repeat every flag.
        $grace = (int) ($this->option('grace') ?? config('qeema.index.publish_grace_seconds'));

        $pending = $staleness->pending($limit, $grace);

        if ($pending->isEmpty()) {
            $this->info('No stale snapshots.');

            return self::SUCCESS;
        }

        $this->info("Recomputing {$pending->count()} stale snapshot(s).");
        $bar = $this->output->createProgressBar($pending->count());

        $failed = 0;

        foreach ($pending as $snapshot) {
            try {
                $calculator->calculate(
                    $snapshot->country,
                    $snapshot->location,
                    $snapshot->basket,
                    CarbonImmutable::instance($snapshot->snapshot_date->toDateTime()),
                );
            } catch (Throwable $e) {
                // One snapshot must not stop the rest. Without this a single
                // row that throws takes the whole drain down with it — and
                // because the task runs every minute and always starts with the
                // oldest, that one row blocks every other snapshot from ever
                // being recomputed. The failure mode is silent: the command
                // dies, the scheduler reports having run, and corrections stop
                // reaching the published figures for good.
                $failed++;

                Log::error('Recomputing a snapshot failed; continuing with the rest', [
                    'snapshot_id' => $snapshot->id,
                    'location_id' => $snapshot->location_id,
                    'snapshot_date' => $snapshot->snapshot_date->toDateString(),
                    'exception' => $e::class,
                    'reason' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$staleness->pendingCount()} still pending.");

        if ($failed > 0) {
            $this->warn("{$failed} snapshot(s) could not be recomputed; see the log for each.");
        }

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
