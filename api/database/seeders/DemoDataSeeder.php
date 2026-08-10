<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Synthetic\SyntheticDataGenerator;
use Illuminate\Database\Seeder;

/**
 * Generates the synthetic demo history for every configured country.
 *
 * This is what makes `docker compose up` yield a system with something in it
 * rather than an empty shell (constraint C2). Everything it writes is marked
 * synthetic in `device_metadata` and sourced from a `synthetic` FX provider, so
 * demo data is always distinguishable from real reports.
 */
final class DemoDataSeeder extends Seeder
{
    public function __construct(
        private readonly CountryConfigLoader $loader = new CountryConfigLoader,
    ) {}

    public function run(): void
    {
        $directory = (string) config('qeema.countries_path');
        $months = (int) config('qeema.seed.demo_months', 6);
        $seed = (int) config('qeema.seed.demo_seed', 20260101);

        if (! is_dir($directory)) {
            $this->command?->warn("Country configuration directory not found: {$directory}");

            return;
        }

        foreach ($this->loader->loadDirectory($directory) as $config) {
            $code = strtoupper((string) $config['country']['code']);
            $country = Country::query()->where('code', $code)->first();

            if ($country === null) {
                $this->command?->warn("Country {$code} is not seeded; skipping demo data.");

                continue;
            }

            // Skip a country that already has a demo history. The generator
            // plain-inserts its FX rates rather than upserting, so re-running
            // it over existing rows aborts the whole bootstrap on a unique
            // violation — which is what happened the first time a second
            // country was added to an already-seeded deployment.
            if (Submission::query()->where('country_id', $country->id)->exists()) {
                $this->command?->line("Demo data already present for {$code}; skipping.");

                continue;
            }

            /** @var array<string, mixed> $demo */
            $demo = $config['demo'] ?? [];

            if ($demo === []) {
                $this->command?->line("No demo block for {$code}; skipping.");

                continue;
            }

            // The country file may pin its own seed so its demo is reproducible
            // independently of the platform default.
            $demo['months'] = $months;
            $generator = new SyntheticDataGenerator((int) ($demo['seed'] ?? $seed));

            $this->command?->info("Generating {$months} months of synthetic history for {$code}...");

            $summary = $generator->generate(
                $country,
                $demo,
                fn (int $day, int $total) => $this->command?->line("  day {$day}/{$total}"),
            );

            $this->command?->info('  '.$summary->describe());
        }
    }
}
