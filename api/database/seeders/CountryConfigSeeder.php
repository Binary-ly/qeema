<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\CountryConfig\CountryConfigException;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Illuminate\Database\Seeder;

/**
 * Loads every configured country from countries/*.yaml into the database.
 *
 * Runs automatically during `qeema:bootstrap`, which is what makes
 * `docker compose up` produce a configured system with no manual steps.
 */
final class CountryConfigSeeder extends Seeder
{
    public function __construct(
        private readonly CountryConfigLoader $loader = new CountryConfigLoader,
        private readonly CountryConfigImporter $importer = new CountryConfigImporter,
    ) {}

    public function run(): void
    {
        $directory = (string) config('qeema.countries_path');
        $selection = (string) config('qeema.seed_countries', '*');

        $onlyCodes = $selection === '*'
            ? null
            : array_values(array_filter(array_map('trim', explode(',', $selection))));

        if (! is_dir($directory)) {
            $this->command?->warn("Country configuration directory not found: {$directory}");

            return;
        }

        try {
            $configs = $this->loader->loadDirectory($directory, $onlyCodes);
        } catch (CountryConfigException $e) {
            // Fail loudly and specifically. A deployment booting with a silently
            // skipped country would serve an empty index that looks like a data
            // problem rather than a configuration mistake.
            $this->command?->error($e->getMessage());

            throw $e;
        }

        if ($configs === []) {
            $this->command?->warn("No country configuration files matched in {$directory}.");

            return;
        }

        foreach ($configs as $config) {
            $summary = $this->importer->import($config);
            $this->command?->info('Imported '.$summary->describe());
        }
    }
}
