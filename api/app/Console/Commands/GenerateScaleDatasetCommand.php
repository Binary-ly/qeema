<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\Location;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Synthetic\ReporterCorpus;
use App\Support\Synthetic\SyntheticDataGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds a large, deliberately harder dataset for load and robustness testing.
 *
 * Two things separate this from the shipped demo, and both are the point.
 *
 * **Volume.** The demo is about 21,000 observations, which proves nothing about
 * behaviour at scale — and every performance claim this platform might make is
 * currently unmeasured. The knobs here reach millions of rows.
 *
 * **Text the matcher was not tuned against.** The demo's reporter text is the
 * catalogue name with mutations applied — hamza reintroduced, digits switched to
 * Arabic-Indic form, a typo inserted. Those are the same transformations the
 * matcher's normaliser undoes, because both were written from the same list. So
 * a matching score measured against it is partly a measure of whether the
 * normaliser was implemented correctly. This uses `countries/corpus/*.json`
 * instead: wordings that no rule the matcher knows produced.
 *
 * **What it is not.** Still synthetic, and the corpus was authored by a language
 * model, so its realism is asserted rather than measured. A figure measured here
 * is not a figure measured against a market, and must not be quoted as one.
 *
 * Runs on a country with no demo data — the generator plain-inserts exchange
 * rates rather than upserting, so a second run over the same dates aborts on a
 * unique violation. Bootstrap with `--skip-demo` first.
 */
final class GenerateScaleDatasetCommand extends Command
{
    /**
     * How far outside the country's known locations a corpus location may sit
     * before it is treated as a mistake.
     *
     * Derived from the country's own configured locations rather than from a
     * hardcoded box, because a hardcoded box would be a country literal in
     * application source (C3). Generous: this is meant to catch a place put on
     * the wrong continent, not to adjudicate borders.
     */
    private const COORDINATE_MARGIN_DEGREES = 4.0;

    protected $signature = 'qeema:demo:scale
                            {--country= : ISO code}
                            {--days=730 : Days of history to generate}
                            {--locations=0 : How many corpus locations to add before generating}
                            {--reports-per-cell=1 : Reporters who may report the same item, place and day}
                            {--reporters=4 : Reporters per location}
                            {--observation-rate= : Share of cells that get any report at all}
                            {--distractor-rate=1.5 : Average submissions per location-day that match nothing at all}
                            {--seed= : Override the country demo seed}';

    protected $description = 'Generate a large corpus-backed dataset for load and robustness testing';

    public function handle(CountryConfigLoader $loader): int
    {
        $code = strtoupper((string) $this->option('country'));

        if ($code === '') {
            $this->error('--country is required.');

            return self::FAILURE;
        }

        $country = Country::query()->where('code', $code)->first();

        if ($country === null) {
            $this->error("Country {$code} is not seeded. Run qeema:bootstrap first.");

            return self::FAILURE;
        }

        if (Submission::query()->where('country_id', $country->id)->exists()) {
            // Said plainly rather than half-generating: the generator inserts
            // exchange rates without upserting, so this would abort partway and
            // leave a dataset nobody can reason about.
            $this->error("{$code} already has submissions. This needs a country with no demo data — run:");
            $this->line('  php artisan qeema:bootstrap --force --fresh --skip-demo');

            return self::FAILURE;
        }

        $corpus = ReporterCorpus::forCountry($code);

        if ($corpus->isEmpty()) {
            $this->error('No corpus at countries/corpus/'.strtolower($code).'.json — nothing to make this harder than the demo.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s corpus: %d items, %d phrasings, %d locations available.',
            $code,
            count(array_filter(array_map(
                fn (string $c): bool => $corpus->phrasingsFor($c) !== [],
                $country->canonicalItems()->pluck('code')->all(),
            ))),
            $corpus->phrasingCount(),
            count($corpus->locations()),
        ));

        $added = $this->addLocations($country, $corpus, (int) $this->option('locations'));

        $demo = $this->demoConfig($loader, $code);

        if ($demo === null) {
            return self::FAILURE;
        }

        return $this->generate($country, $demo, $corpus, $added);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function demoConfig(CountryConfigLoader $loader, string $code): ?array
    {
        $directory = (string) config('qeema.countries_path');

        try {
            $configs = $loader->loadDirectory($directory, [$code]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }

        /** @var array<string, mixed> $demo */
        $demo = $configs[0]['demo'] ?? [];

        if ($demo === []) {
            $this->error("Country {$code} has no demo block to base a dataset on.");

            return null;
        }

        return $demo;
    }

    /**
     * Add corpus locations the country does not already have.
     *
     * Coordinates are checked against the spread of the country's existing
     * locations. The corpus is model-authored, and a plausible-looking place
     * with coordinates in the wrong country would quietly corrupt every
     * distance-based feature that reads them.
     */
    private function addLocations(Country $country, ReporterCorpus $corpus, int $wanted): int
    {
        if ($wanted <= 0) {
            return 0;
        }

        $existing = $country->locations()->get();
        $known = $existing->pluck('slug')->flip();

        $bounds = [
            'minLat' => $existing->min('latitude') - self::COORDINATE_MARGIN_DEGREES,
            'maxLat' => $existing->max('latitude') + self::COORDINATE_MARGIN_DEGREES,
            'minLon' => $existing->min('longitude') - self::COORDINATE_MARGIN_DEGREES,
            'maxLon' => $existing->max('longitude') + self::COORDINATE_MARGIN_DEGREES,
        ];

        $added = 0;
        $rejected = 0;

        foreach ($corpus->locations() as $candidate) {
            if ($added >= $wanted) {
                break;
            }

            $slug = (string) ($candidate['slug'] ?? '');

            if ($slug === '' || $known->has($slug)) {
                continue;
            }

            $latitude = (float) ($candidate['latitude'] ?? 0);
            $longitude = (float) ($candidate['longitude'] ?? 0);

            if (
                $latitude < $bounds['minLat'] || $latitude > $bounds['maxLat']
                || $longitude < $bounds['minLon'] || $longitude > $bounds['maxLon']
            ) {
                $this->warn(sprintf('  rejected %s: %.3f,%.3f is outside this country', $slug, $latitude, $longitude));
                $rejected++;

                continue;
            }

            Location::query()->create([
                'country_id' => $country->id,
                'slug' => $slug,
                'name' => (string) ($candidate['name'] ?? $slug),
                'name_local' => $candidate['name_local'] ?? null,
                'admin1_name' => $candidate['admin1_name'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'population_estimate' => isset($candidate['population_estimate'])
                    ? (int) $candidate['population_estimate']
                    : null,
                'is_active' => true,
            ]);

            $added++;
        }

        $this->line(sprintf('Added %d location(s)%s.', $added, $rejected > 0 ? ", rejected {$rejected}" : ''));

        return $added;
    }

    /**
     * @param  array<string, mixed>  $demo
     */
    private function generate(Country $country, array $demo, ReporterCorpus $corpus, int $addedLocations): int
    {
        $demo['days'] = (int) $this->option('days');
        $demo['reports_per_cell'] = (int) $this->option('reports-per-cell');
        $demo['reporters_per_location'] = (int) $this->option('reporters');
        $demo['corpus'] = $corpus;
        $demo['distractor_rate'] = (float) $this->option('distractor-rate');

        if ($this->option('observation-rate') !== null) {
            $demo['observation_rate'] = (float) $this->option('observation-rate');
        }

        $locations = $country->locations()->where('is_active', true)->count();
        $items = $country->canonicalItems()->where('is_active', true)->count();

        $this->info(sprintf(
            'Generating: %d locations x %d items x %d days, up to %d report(s) per cell.',
            $locations,
            $items,
            $demo['days'],
            $demo['reports_per_cell'],
        ));

        $this->line(sprintf(
            '  %d distractor wording(s) available; about %.1f per location-day will match nothing.',
            count($corpus->distractors()),
            $demo['distractor_rate'],
        ));

        $seed = (int) ($this->option('seed') ?? $demo['seed'] ?? 20260101);
        $generator = new SyntheticDataGenerator($seed);

        $startedAt = microtime(true);

        $summary = $generator->generate(
            $country,
            $demo,
            fn (int $day, int $total) => $day % 90 === 0
                ? $this->line(sprintf('  day %d/%d  (%s elapsed)', $day, $total, $this->elapsed($startedAt)))
                : null,
        );

        $seconds = max(0.001, microtime(true) - $startedAt);

        $this->newLine();
        $this->info('Done in '.$this->elapsed($startedAt).'.');
        $this->line('  '.$summary->describe());
        $this->line(sprintf(
            '  %s rows/second sustained across submissions, observations and ground truth.',
            number_format(($summary->submissions * 2 + $summary->groundTruthCells) / $seconds, 0),
        ));

        $this->table(
            ['table', 'rows'],
            collect([
                'price_observations' => DB::table('price_observations')->count(),
                'submissions' => DB::table('submissions')->count(),
                'resolutions' => DB::table('resolutions')->count(),
                'anomaly_scores' => DB::table('anomaly_scores')->count(),
                'qeema_eval.gt_prices' => DB::table('qeema_eval.gt_prices')->count(),
                'unmatchable (no right answer)' => DB::table('qeema_eval.gt_submissions')
                    ->whereNull('true_canonical_item_id')->count(),
                'locations (added)' => $addedLocations,
            ])->map(fn ($count, $table): array => [$table, number_format((float) $count)])->values()->all(),
        );

        return self::SUCCESS;
    }

    private function elapsed(float $startedAt): string
    {
        return CarbonImmutable::now()
            ->subSeconds((int) round(microtime(true) - $startedAt))
            ->diffForHumans(['parts' => 2, 'short' => true, 'syntax' => CarbonImmutable::DIFF_ABSOLUTE]);
    }
}
