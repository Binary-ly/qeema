<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ScoreSubmissionAnomaly;
use App\Models\AnomalyScore;
use App\Models\PriceObservation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Screens a newly created observation before it can move a published figure.
 *
 * Separate from resolution because the two fail differently. Resolution can be
 * wrong about *which item*; screening can be wrong about *whether the price is
 * plausible*, and it needs the observation to exist before it can compare it to
 * anything. Chaining them in one job would mean a transient scoring failure
 * re-running a resolution that already succeeded.
 *
 * Doing nothing is a valid outcome. When the ML service has no opinion the
 * action records no verdict at all — an unscored observation is one nobody has
 * judged yet, and inventing a clean verdict would let bad data through at
 * precisely the moment the system is least able to notice. The sweeper picks
 * the observation up again on the next tick.
 */
final class ScoreSubmissionAnomalyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 60;

    public function __construct(public readonly string $submissionId)
    {
        $this->onQueue((string) config('qeema.pipeline.queue_live'));
    }

    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    public function tries(): int
    {
        return (int) config('qeema.pipeline.max_attempts');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ScoreSubmissionAnomaly $scorer): void
    {
        // Already judged. Re-scoring would stack a second verdict on the same
        // submission and, where the first was `rejected`, could flip a
        // deliberately invalidated observation back into the index.
        if (AnomalyScore::query()->where('submission_id', $this->submissionId)->exists()) {
            return;
        }

        $observation = PriceObservation::query()
            // The item comes with it: screening reads the catalogue's sourced
            // reference price off it when the item has no observed history to
            // be judged against.
            ->with('canonicalItem')
            ->where('submission_id', $this->submissionId)
            ->first();

        if ($observation === null) {
            return;
        }

        /** @var Collection<int, PriceObservation> $batch */
        $batch = collect([$observation]);

        $scorer->handle($batch);
    }
}
