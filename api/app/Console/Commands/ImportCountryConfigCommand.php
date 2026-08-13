<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CountryConfig\CountryConfigException;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Illuminate\Console\Command;

/**
 * Applies an edit to `countries/*.yaml` to a running deployment.
 *
 * There was no way to do this. `qeema:bootstrap` seeds a country only when it is
 * absent — a deliberate guard so routine container restarts stay cheap — so
 * editing a country file and restarting printed "already seeded; skipping" and
 * changed nothing. The importer underneath has always been idempotent and says
 * so in its own docblock; nothing exposed it.
 *
 * That mattered most for basket revisions, which are expressed by editing the
 * country file and are the whole reason chain-linking exists: without this the
 * revision could not be applied, and the feature could not be reached by anyone
 * following the runbook.
 *
 * Additive, like the importer: an item removed from the YAML is deactivated
 * rather than deleted, because published figures point at it.
 */
final class ImportCountryConfigCommand extends Command
{
    protected $signature = 'qeema:config:import
                            {--country= : ISO code; defaults to every configured country}
                            {--dry-run : Validate and report without writing}';

    protected $description = 'Apply countries/*.yaml to the database';

    public function handle(CountryConfigLoader $loader, CountryConfigImporter $importer): int
    {
        $directory = (string) config('qeema.countries_path');

        if (! is_dir($directory)) {
            $this->error("Country configuration directory not found: {$directory}");

            return self::FAILURE;
        }

        $only = $this->option('country')
            ? [strtoupper((string) $this->option('country'))]
            : null;

        try {
            $configs = $loader->loadDirectory($directory, $only);
        } catch (CountryConfigException $e) {
            // A malformed file is an operator's mistake to fix, and the loader's
            // message names the field. Importing the rest and leaving one country
            // silently stale would be worse than stopping.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($configs === []) {
            $this->error($only === null
                ? "No country configuration files found in {$directory}."
                : 'No configuration file matches --country='.$this->option('country'));

            return self::FAILURE;
        }

        foreach ($configs as $config) {
            $this->importOne($config, $importer);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function importOne(array $config, CountryConfigImporter $importer): void
    {
        /** @var array<string, mixed> $countryData */
        $countryData = $config['country'];
        $code = strtoupper((string) $countryData['code']);

        if ($this->option('dry-run')) {
            // Reaching here means the file parsed and validated, which is most
            // of what a dry run is for.
            $this->line("{$code}: configuration is valid (dry run, nothing written).");

            return;
        }

        $summary = $importer->import($config);

        $this->line(sprintf(
            '%s: %d unit(s), %d location(s), %d item(s), %d variant(s), %d basket item(s), %d source(s).',
            $code,
            $summary->units,
            $summary->locations,
            $summary->canonicalItems,
            $summary->variants,
            $summary->basketItems,
            $summary->sources,
        ));

        // A revision only becomes visible once the new version is anchored, and
        // forgetting that step publishes a null level for every location. Said
        // here rather than left to the runbook, because this is the moment an
        // operator finds out.
        $this->comment("  If this introduced a basket revision, run: php artisan qeema:index:link --country={$code}");
    }
}
