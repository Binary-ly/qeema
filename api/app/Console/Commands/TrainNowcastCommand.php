<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Index\NowcastFeatureBuilder;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Teaches the nowcast model from observations that already happened.
 *
 * Until this existed nothing in the platform ever called the training endpoint,
 * so the quantile models were never fitted in a deployment and imputation fell
 * back to a ±30% heuristic every time. The model card described a component no
 * running system had ever used.
 *
 * **Every row is a rehearsal of the thing the model will be asked to do.** The
 * target is a price that *was* observed; the features are assembled as if it
 * had not been, using `NowcastFeatureBuilder`, which never reads an observation
 * of the same item at the same location on or after the target date. Get that
 * wrong and the model learns to read the answer off its own inputs: it
 * evaluates beautifully, ships, and imputes badly, and nothing in the numbers
 * says why.
 *
 * The same builder serves the imputer, so what is learned here is what is seen
 * there.
 */
final class TrainNowcastCommand extends Command
{
    protected $signature = 'qeema:nowcast:train
                            {--country= : ISO code; defaults to every active country}
                            {--days= : How far back to draw training rows from; defaults to the country\'s index.nowcast_training_days}
                            {--limit=4000 : Maximum rows per country}';

    protected $description = 'Fit the nowcast quantile models on observed prices';

    public function handle(MlClientInterface $ml, NowcastFeatureBuilder $features): int
    {
        if (! $ml->isAvailable()) {
            // Nothing is lost by waiting: this runs on a schedule, and the
            // imputer keeps using its labelled fallback in the meantime.
            $this->warn('The ML service is unavailable; skipping training.');

            return self::SUCCESS;
        }

        $countries = Country::query()
            ->where('is_active', true)
            ->when(
                $this->option('country'),
                fn ($query) => $query->where('code', strtoupper((string) $this->option('country'))),
            )
            ->get();

        foreach ($countries as $country) {
            $this->trainFor($country, $ml, $features);
        }

        return self::SUCCESS;
    }

    private function trainFor(Country $country, MlClientInterface $ml, NowcastFeatureBuilder $features): void
    {
        $rows = $this->collect($country, $features);

        if ($rows['features'] === []) {
            $this->line("{$country->code}: not enough history to train on yet.");

            return;
        }

        $result = $ml->trainNowcast($country, $rows['features'], $rows['targets']);

        if ($result === null) {
            $this->warn("{$country->code}: the ML service declined to train.");

            return;
        }

        $trained = $result['trained'];

        $this->line(sprintf(
            '%s: %s on %d rows — %s',
            $country->code,
            $trained ? 'trained' : 'declined',
            $result['n_samples'],
            $result['reason'],
        ));

        Log::info('Nowcast training run', [
            'country' => $country->code,
            'rows' => count($rows['features']),
            'trained' => $trained,
        ]);
    }

    /**
     * Assemble training rows: a real price, and the context of not knowing it.
     *
     * The target is a *ratio* to the national median, not a price: one model
     * then serves every item at every scale, and does not relearn inflation as
     * signal each month.
     *
     * @return array{features: list<array<string, float>>, targets: list<float>}
     */
    private function collect(Country $country, NowcastFeatureBuilder $features): array
    {
        // The horizon is the country's, not the command's. A daily crowd
        // fills 120 days with thousands of rows; a monthly survey fills it
        // with four per series, and the model rightly declines. The country
        // file says how its sources report, so it says how far back to look.
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : $country->indexSettings()['nowcast_training_days'];

        $since = CarbonImmutable::now($country->timezone)
            ->subDays($days)
            ->toDateString();

        $observations = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('observed_on', '>=', $since)
            ->valid()
            // Oldest first, so a capped run trains on a contiguous stretch of
            // history rather than a sample skewed towards whichever rows the
            // database happened to return.
            ->orderBy('observed_on')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'location_id', 'canonical_item_id', 'observed_on', 'normalized_price_per_base_unit']);

        if ($observations->isEmpty()) {
            return ['features' => [], 'targets' => []];
        }

        $locations = Location::query()
            ->whereIn('id', $observations->pluck('location_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];
        $targets = [];
        $bar = $this->output->createProgressBar($observations->count());

        foreach ($observations as $observation) {
            $location = $locations->get($observation->location_id);

            if ($location === null) {
                $bar->advance();

                continue;
            }

            $asOf = CarbonImmutable::parse($observation->observed_on->toDateString());

            $row = $features->build(
                $country,
                $location,
                (int) $observation->canonical_item_id,
                $asOf,
            );

            // Without a national reference the target cannot be expressed as a
            // ratio, and the model is trained entirely on ratios so that one
            // model serves every item at every price scale.
            if ($row['national_median'] <= 0.0) {
                $bar->advance();

                continue;
            }

            $rows[] = $row;
            $targets[] = (float) $observation->normalized_price_per_base_unit / $row['national_median'];

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return ['features' => $rows, 'targets' => $targets];
    }
}
