<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ResolveSubmission;
use App\Models\Resolution;
use App\Models\Submission;
use App\Services\Ml\MlClientInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Carries one submission from `pending` to a price observation, or to a human.
 *
 * This job is the wire that was missing. Every stage it calls was built and
 * tested; nothing in the running system ever called them, so a price submitted
 * through the public API sat at `pending` forever.
 *
 * Three properties matter more than speed here.
 *
 * **It is idempotent.** It does nothing at all unless the submission is still
 * pending, so a retry, a double dispatch and a sweeper adopting work already in
 * flight are all harmless. Underneath, `price_observations.submission_id` is
 * UNIQUE, so even a genuine race ends with one observation rather than two.
 *
 * **It waits out an outage rather than converting it into human work.** See
 * {@see self::deferWhileMatcherIsDown()}.
 *
 * **It never ends in silence.** A submission the pipeline cannot process is
 * handed to a reviewer with the error attached, because the alternative is the
 * failure this whole phase exists to eliminate: a row nobody is looking at.
 */
final class ResolveSubmissionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Delay ladder, in seconds, for deferrals and retries.
     *
     * @var list<int>
     */
    private const BACKOFF = [10, 30, 120, 300];

    /**
     * How long the uniqueness lock is held.
     *
     * Must comfortably exceed the worst case of the ladder above (roughly eight
     * minutes), or a submission part-way through waiting out an ML outage could
     * have a second job dispatched alongside it by the sweeper. Long enough to
     * cover the deferral, short enough that a submission whose job vanished
     * entirely is adopted again within the quarter hour.
     */
    public int $uniqueFor = 900;

    /**
     * Below the queue's `retry_after`, deliberately.
     *
     * A job that outlives `retry_after` is handed to a second worker while the
     * first is still running. The unique index would catch the duplicate
     * observation, but as an error rather than as the design.
     */
    public int $timeout = 60;

    public function __construct(public readonly string $submissionId)
    {
        $this->onQueue((string) config('qeema.pipeline.queue_live'));
    }

    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    /**
     * A backstop only. The authoritative budget is `pipeline_attempts` on the
     * submission row, which survives re-dispatch; this merely stops the queue
     * outliving it.
     */
    public function tries(): int
    {
        return (int) config('qeema.pipeline.max_attempts');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return self::BACKOFF;
    }

    public function handle(ResolveSubmission $resolver, MlClientInterface $ml): void
    {
        $submission = Submission::query()->find($this->submissionId);

        // Already resolved, already reviewed, or deleted. Doing nothing is the
        // correct response to all three, and is what makes double dispatch safe.
        if ($submission === null || $submission->status !== Submission::STATUS_PENDING) {
            return;
        }

        if ($this->deferWhileMatcherIsDown($submission, $ml)) {
            return;
        }

        try {
            $resolver->handle($submission);
        } catch (UniqueConstraintViolationException) {
            // Another worker got there first and the unique index said so. The
            // outcome is exactly what this job wanted, so it is a success.
            return;
        } catch (Throwable $e) {
            $this->recordFailure($submission, $e);

            return;
        }

        $this->scoreIfObserved($submission);
    }

    /**
     * Wait out a matching-service outage instead of flooding the review queue.
     *
     * `ResolveSubmission` routes to human review when the matcher has no
     * opinion, and for a direct call that is right: never guess. As the
     * automatic response to a container restart it is a bad trade — a thousand
     * submissions that would each have resolved in milliseconds become a
     * thousand items of human work, and a review queue nobody can drain is how
     * a deployment quietly dies.
     *
     * So the deferral lives here rather than in the action, leaving the
     * action's tested semantics untouched. The last attempt deliberately falls
     * through: if the outage has outlasted the budget, a human should be told
     * about the submission rather than the platform waiting indefinitely.
     */
    private function deferWhileMatcherIsDown(Submission $submission, MlClientInterface $ml): bool
    {
        if ($ml->isAvailable()) {
            return false;
        }

        $submission->recordPipelineAttempt('Matching service unavailable; deferred.');

        if ($submission->pipelineBudgetExhausted()) {
            return false;
        }

        $this->release($this->delayFor($submission->pipeline_attempts));

        return true;
    }

    /**
     * Hand the observation to anomaly screening.
     *
     * A separate job rather than an inline call: scoring has its own failure
     * mode and its own retry budget, and folding it in here would mean a
     * transient scoring failure re-running a resolution that already succeeded.
     */
    private function scoreIfObserved(Submission $submission): void
    {
        $submission->refresh();

        if ($submission->status !== Submission::STATUS_RESOLVED) {
            return;
        }

        if ($submission->priceObservation === null) {
            return;
        }

        ScoreSubmissionAnomalyJob::dispatch($this->submissionId);
    }

    /**
     * Count the attempt, and hand over to a human once the budget is gone.
     */
    private function recordFailure(Submission $submission, Throwable $e): void
    {
        $submission->recordPipelineAttempt(Str::limit($e->getMessage(), 480));

        Log::warning('Submission resolution failed', [
            'submission_id' => $submission->id,
            'attempt' => $submission->pipeline_attempts,
            'error' => $e->getMessage(),
        ]);

        if (! $submission->pipelineBudgetExhausted()) {
            // Let the queue retry with backoff. Rethrowing rather than
            // releasing keeps the exception visible in Horizon, where an
            // operator can see what is actually breaking.
            throw $e;
        }

        $this->handToReview($submission, $e);
    }

    /**
     * The terminal state for work the pipeline cannot do.
     *
     * Not a deletion, not an indefinite retry, and not a silent `pending`: a
     * row in the review queue carrying the reason it got there.
     */
    private function handToReview(Submission $submission, Throwable $e): void
    {
        Resolution::query()->updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'method' => Resolution::METHOD_RULE,
                'reviewed' => false,
                'notes' => sprintf(
                    'Automatic resolution failed %d times and was handed to review. Last error: %s',
                    $submission->pipeline_attempts,
                    Str::limit($e->getMessage(), 300),
                ),
            ],
        );

        $submission->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW])->save();

        Log::error('Submission handed to review after exhausting pipeline attempts', [
            'submission_id' => $submission->id,
            'attempts' => $submission->pipeline_attempts,
        ]);
    }

    /**
     * Last resort, for a failure the handler never saw — a timeout that killed
     * the process, or an exception thrown outside the guarded block.
     */
    public function failed(?Throwable $e): void
    {
        $submission = Submission::query()->find($this->submissionId);

        if ($submission === null || $submission->status !== Submission::STATUS_PENDING) {
            return;
        }

        $this->handToReview($submission, $e ?? new RuntimeException('Job failed without an exception.'));
    }

    private function delayFor(int $attempt): int
    {
        $index = min(max($attempt, 1), count(self::BACKOFF)) - 1;

        return self::BACKOFF[$index];
    }
}
