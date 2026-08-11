<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\FxRate;
use App\Services\Fx\FxProviderRegistry;
use App\Services\Fx\FxQuote;
use App\Services\Fx\Providers\ManualFxProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Fetches today's exchange rate for every country that has a source.
 *
 * Countries on manual entry are a no-op here by design, and the great majority
 * will be: the rate that matters in these economies is the parallel one, and it
 * usually has no free machine-readable feed. What keeps that honest is not this
 * command but the health check, which warns before the last entered rate goes
 * stale enough for dollar figures to be withdrawn.
 *
 * The date is the country's own, for the same reason the index roll-forward's
 * is: a rate stamped with the server's date lands on the wrong day for half the
 * world.
 */
final class FetchFxRatesCommand extends Command
{
    protected $signature = 'qeema:fx:fetch
                            {--country= : ISO code; defaults to every active country}
                            {--date= : Fetch for a specific date rather than today}';

    protected $description = "Fetch exchange rates from each country's configured source";

    public function handle(FxProviderRegistry $registry): int
    {
        if (! config('qeema.fx.fetch_enabled')) {
            $this->info('Automatic exchange rate fetching is disabled.');

            return self::SUCCESS;
        }

        $countries = Country::query()
            ->where('is_active', true)
            ->when(
                $this->option('country'),
                fn ($query) => $query->where('code', strtoupper((string) $this->option('country'))),
            )
            ->get();

        if ($countries->isEmpty()) {
            $this->info('No active countries.');

            return self::SUCCESS;
        }

        $fetched = 0;
        $manual = 0;

        foreach ($countries as $country) {
            $provider = $registry->for($country);

            if ($provider->key() === ManualFxProvider::KEY) {
                $this->line("{$country->code}: rates are entered by hand.");
                $manual++;

                continue;
            }

            $date = $this->dateFor($country);
            $quote = $provider->fetch($country, $date);

            if ($quote === null || ! $quote->hasAnyRate()) {
                // The provider has already logged why. Publishing nothing is
                // correct: the resolver falls back to the last usable rate and
                // marks it stale rather than inventing one.
                $this->warn("{$country->code}: no rate from {$provider->key()}.");

                continue;
            }

            $this->store($country, $quote, $date);
            $fetched++;

            $this->line(sprintf(
                '%s: %s official=%s parallel=%s',
                $country->code,
                $date->toDateString(),
                $quote->officialRate === null ? '—' : (string) $quote->officialRate,
                $quote->parallelRate === null ? '—' : (string) $quote->parallelRate,
            ));
        }

        $this->info("Fetched {$fetched} rate(s); {$manual} country(ies) on manual entry.");

        return self::SUCCESS;
    }

    /**
     * Upsert on `(country_id, rate_date, source)`.
     *
     * Keyed by source, so a fetched rate sits alongside anything an operator
     * typed for the same day rather than replacing it. Which of the two is used
     * is the resolver's decision, and it prefers the human.
     */
    private function store(Country $country, FxQuote $quote, CarbonImmutable $date): void
    {
        FxRate::query()->updateOrCreate(
            [
                'country_id' => $country->id,
                'rate_date' => $date->toDateString(),
                'source' => $quote->source,
            ],
            [
                'official_rate' => $quote->officialRate,
                'parallel_rate' => $quote->parallelRate,
                'base_currency' => $quote->baseCurrency,
                'is_manual' => false,
                'raw' => $quote->raw,
                'fetched_at' => CarbonImmutable::now(),
            ],
        );
    }

    private function dateFor(Country $country): CarbonImmutable
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            return CarbonImmutable::parse($date)->startOfDay();
        }

        return CarbonImmutable::parse(CarbonImmutable::now($country->timezone)->toDateString())->startOfDay();
    }
}
