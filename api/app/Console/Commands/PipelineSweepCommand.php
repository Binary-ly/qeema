<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResolveSubmissionJob;
use App\Jobs\ScoreSubmissionAnomalyJob;
use App\Models\PriceObservation;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The reconciler: nothing stays stuck, whatever put it there.
 *
 * Dispatching a job when a submission is written is the fast path, and it is
 * not a guarantee. `RecordSubmission` is not the only writer — the partner
 * importer inserts with the query builder, so no model event fires — and any
 * future importer will be written by somebody who has not read this file. A
 * queue flush, a killed worker or a lost job produce the same silence.
 *
 * So this runs every minute and adopts anything the fast path missed. It is the
 * difference between a pipeline that usually works and one that can be relied
 * on, and it is why the eleven submissions stranded before this phase needed no
 * migration script: they are simply the first tick's work.
 *
 * Everything it dispatches is idempotent, so overlapping with work already in
 * flight costs a no-op rather than a duplicate.
 */
final class PipelineSweepCommand extends Command
{
    protected $signature = 'qeema:pipeline:sweep
                            {--age= : Seconds a submission must have waited before adoption}
                            {--limit= : Maximum dispatches of each kind per run}
                            {--now : Adopt everything pending, ignoring the age threshold}';

    protected $description = 'Dispatch pipeline work for submissions and observations the fast path missed';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?? config('qeema.pipeline.sweep_limit'));
        $age = (int) ($this->option('age') ?? config('qeema.pipeline.sweep_age_seconds'));

        $resolved = $this->sweepUnresolved($limit, $this->option('now') === true ? 0 : $age);
        $scored = $this->sweepUnscored($limit);

        if ($resolved === 0 && $scored === 0) {
            // Silent on an idle system. This runs every minute; a line per tick
            // would bury the ticks that matter.
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        Log::info('Pipeline sweep dispatched work', [
            'unresolved' => $resolved,
            'unscored' => $scored,
        ]);

        $this->info("Dispatched {$resolved} resolution(s) and {$scored} anomaly scoring job(s).");

        return self::SUCCESS;
    }

    /**
     * Submissions still pending after the grace age.
     *
     * Age is measured on `created_at`, which the existing `(status, created_at)`
     * index serves, and which for inbound data is the same instant as
     * `ingested_at`. The threshold exists so the sweeper does not race the
     * dispatch that has already happened for a submission written a moment ago.
     */
    private function sweepUnresolved(int $limit, int $ageSeconds): int
    {
        $cutoff = CarbonImmutable::now()->subSeconds($ageSeconds);

        $ids = Submission::query()
            ->awaitingPipeline()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ResolveSubmissionJob::dispatch((string) $id)
                ->onQueue((string) config('qeema.pipeline.queue_bulk'));
        }

        return $ids->count();
    }

    /**
     * Valid observations nobody has screened, within the recent window.
     *
     * These arise when the ML service had no opinion at scoring time: the
     * action deliberately records no verdict rather than inventing a clean one,
     * which leaves the observation valid, published and unscreened. Retrying
     * every minute is the intended behaviour — it resolves itself the moment
     * the service returns.
     *
     * The window is what stops that being a stampede. A seeded deployment holds
     * tens of thousands of observations written wholesale rather than through
     * the pipeline; without a bound this would re-dispatch them every minute
     * for the life of the deployment.
     */
    private function sweepUnscored(int $limit): int
    {
        $window = CarbonImmutable::now()
            ->subHours((int) config('qeema.pipeline.sweep_scoring_window_hours'));

        $ids = PriceObservation::query()
            ->valid()
            ->where('created_at', '>=', $window)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('anomaly_scores')
                    ->whereColumn('anomaly_scores.submission_id', 'price_observations.submission_id');
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('submission_id');

        foreach ($ids as $id) {
            ScoreSubmissionAnomalyJob::dispatch((string) $id)
                ->onQueue((string) config('qeema.pipeline.queue_bulk'));
        }

        return $ids->count();
    }
}
