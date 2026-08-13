<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Basket;
use App\Models\Country;
use App\Services\Index\ChainLinker;
use App\Services\Index\LinkReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Establishes the anchors that give published levels a reference period.
 *
 * Run after importing a country and after introducing a basket revision. It is
 * idempotent: an existing anchor is left alone, because rewriting one silently
 * restates every figure already published behind it (D-21).
 *
 * Versions are processed in order, since chaining a version needs its
 * predecessor anchored first — a v3 cannot be carried forward from a v2 that has
 * no reference point of its own.
 */
final class LinkIndexCommand extends Command
{
    protected $signature = 'qeema:index:link
                            {--country= : ISO code; defaults to every active country}
                            {--basket= : A single basket version number}
                            {--force : Replace anchors that already exist}';

    protected $description = 'Anchor each basket version so index levels are comparable across revisions';

    public function handle(ChainLinker $linker): int
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->when($this->option('country'), fn ($query) => $query->where(
                'code',
                strtoupper((string) $this->option('country')),
            ))
            ->orderBy('code')
            ->get();

        if ($countries->isEmpty()) {
            // Only an error when a specific country was named and did not exist.
            // No active countries at all is a legitimate state for a fresh
            // install, and failing there would break the bootstrap.
            if ($this->option('country')) {
                $this->error('No active country matches --country='.$this->option('country'));

                return self::FAILURE;
            }

            $this->info('No active countries.');

            return self::SUCCESS;
        }

        foreach ($countries as $country) {
            $this->linkCountry($country, $linker);
        }

        return self::SUCCESS;
    }

    private function linkCountry(Country $country, ChainLinker $linker): void
    {
        $baskets = $country->baskets()
            ->when($this->option('basket'), fn ($query) => $query->where(
                'version',
                (int) $this->option('basket'),
            ))
            // Ascending: a later version chains from the anchor of the one
            // before it, so the predecessor has to be done first.
            ->orderBy('version')
            ->get();

        if ($baskets->isEmpty()) {
            $this->line("{$country->code}: no baskets.");

            return;
        }

        foreach ($baskets as $basket) {
            $this->linkBasket($country, $basket, $linker);
        }
    }

    private function linkBasket(Country $country, Basket $basket, ChainLinker $linker): void
    {
        $this->report($country, $basket, $linker->establish(
            $country,
            $basket,
            (bool) $this->option('force'),
        ));
    }

    private function report(Country $country, Basket $basket, LinkReport $report): void
    {
        $label = "{$country->code} basket v{$basket->version}";

        $this->line(sprintf(
            '%s: %d location(s) anchored via %s%s.',
            $label,
            $report->anchoredCount(),
            $report->method,
            $report->countryFactor === null
                ? ''
                : sprintf(' (country factor %.4f)', $report->countryFactor),
        ));

        if ($report->fallbackCount() > 0) {
            $this->warn(sprintf(
                '  %d location(s) used the country median factor rather than their own.',
                $report->fallbackCount(),
            ));
        }

        foreach ($report->skips() as $skip) {
            $this->line("  skipped {$skip['location']}: {$skip['reason']}");
        }

        if ($report->isEmpty() && $report->skips() !== []) {
            // Nothing anchored means nothing publishes a level for this basket.
            // Worth a log line as well as console output, because this usually
            // runs unattended from the scheduler.
            Log::warning('A basket version was left entirely unanchored', [
                'country' => $country->code,
                'basket_version' => $basket->version,
                'skipped' => count($report->skips()),
            ]);
        }
    }
}
