<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fans a completed partner import out onto the bulk queue.
 *
 * The importer writes its rows with the query builder, in chunks, for speed —
 * which means no model events fire and nothing dispatches per row. This job is
 * the deliberate hand-off, and it targets the bulk queue on purpose: a
 * fifty-thousand-row spreadsheet and a reporter standing in a market are both
 * legitimate work, but only one of them is waiting.
 *
 * Chunked by id rather than loaded at once, because the whole point is that the
 * file may be enormous, and a job that exhausts memory reading its own work
 * list has failed at the only thing it does.
 */
final class ResolveIngestionBatchJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 500;

    public int $timeout = 120;

    public function __construct(public readonly int $ingestionBatchId)
    {
        $this->onQueue((string) config('qeema.pipeline.queue_bulk'));
    }

    public function handle(): void
    {
        $dispatched = 0;

        Submission::query()
            ->where('ingestion_batch_id', $this->ingestionBatchId)
            ->awaitingPipeline()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($submissions) use (&$dispatched): void {
                foreach ($submissions as $submission) {
                    ResolveSubmissionJob::dispatch($submission->id)
                        ->onQueue((string) config('qeema.pipeline.queue_bulk'));

                    $dispatched++;
                }
            });

        Log::info('Dispatched resolution for an ingestion batch', [
            'ingestion_batch_id' => $this->ingestionBatchId,
            'submissions' => $dispatched,
        ]);
    }
}
