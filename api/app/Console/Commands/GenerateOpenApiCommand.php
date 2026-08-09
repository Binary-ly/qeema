<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OpenApi\Generator;

/**
 * Regenerates the published OpenAPI document.
 *
 * Run in CI as well as by hand, with the result compared against the committed
 * file — a spec that has drifted from the code it describes is worse than none,
 * because consumers build against it.
 */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'qeema:openapi {--check : Fail if the committed spec is out of date}';

    protected $description = 'Generate the OpenAPI 3 specification';

    public function handle(): int
    {
        $spec = Generator::scan([app_path('Support/OpenApi')]);
        $json = $spec->toJson();
        $path = public_path('openapi.json');

        if ($this->option('check')) {
            $current = is_file($path) ? (string) file_get_contents($path) : '';

            if (trim($current) !== trim($json)) {
                $this->error('public/openapi.json is out of date. Run: php artisan qeema:openapi');

                return self::FAILURE;
            }

            $this->info('OpenAPI spec is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->info('Wrote '.$path);

        return self::SUCCESS;
    }
}
