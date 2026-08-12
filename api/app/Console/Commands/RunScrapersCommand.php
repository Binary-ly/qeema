<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\IngestionBatch;
use App\Models\Source;
use App\Support\Scraping\ScraperRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the open datasets an operator has configured as sources.
 *
 * `ScraperRunner` was referenced only by its own tests. Everything underneath
 * it worked — pagination, rate limiting, robots.txt, resumable cursors,
 * deterministic idempotency keys — and nothing ran any of it, so a scraper
 * source configured in the admin panel sat there being never fetched. The fifth
 * component found this way.
 *
 * **Nothing is fetched unless an operator configured it.** The platform ships
 * with no scraper source, and the shipped example scraper deliberately targets
 * datasets published for reuse under an open licence rather than somebody's
 * shop website. Running this on a stock deployment does nothing at all, which
 * is why it is safe to have on a schedule.
 *
 * The submissions it creates are ordinary pending submissions, so the pipeline
 * that already exists resolves and screens them. Nothing here is a second path
 * into the index.
 */
final class RunScrapersCommand extends Command
{
    protected $signature = 'qeema:scrape
                            {--source= : Slug of a single source; defaults to every active scraper source}
                            {--country= : ISO code}';

    protected $description = 'Fetch configured open-data sources into submissions';

    public function handle(ScraperRunner $runner): int
    {
        $sources = Source::query()
            ->where('type', Source::TYPE_SCRAPER)
            ->where('is_active', true)
            ->when($this->option('source'), fn ($query) => $query->where('slug', $this->option('source')))
            ->when($this->option('country'), fn ($query) => $query->whereHas(
                'country',
                fn ($q) => $q->where('code', strtoupper((string) $this->option('country'))),
            ))
            ->get();

        if ($sources->isEmpty()) {
            $this->info('No active scraper sources are configured.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->runOne($source, $runner);
        }

        return self::SUCCESS;
    }

    private function runOne(Source $source, ScraperRunner $runner): void
    {
        $batch = $runner->run($source);

        $failed = $batch->status === IngestionBatch::STATUS_FAILED;

        $this->line(sprintf(
            '%s: %s — %d row(s), %d accepted, %d rejected.',
            $source->slug,
            $failed ? 'failed' : 'completed',
            (int) $batch->row_count,
            (int) $batch->accepted_count,
            (int) $batch->rejected_count,
        ));

        if ($failed) {
            // A failing source is an operator's problem to fix — a moved URL, a
            // changed column, a licence that now forbids it — and saying so in
            // the log is the only way they find out, because nothing else in the
            // platform notices a source that has quietly stopped producing.
            Log::warning('A scraper source failed', [
                'source' => $source->slug,
                'error' => $batch->error_report['fatal'] ?? null,
            ]);
        }
    }
}
