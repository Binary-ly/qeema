<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Resolution;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies one "this is not a product" ruling to every identical row queued.
 *
 * The mirror of {@see ClearReviewBacklogJob}. That one turns a reviewer's
 * approval into resolved prices; this one turns a reviewer's ruling that a
 * phrase is noise into rejections, so the greeting waiting a thousand times
 * costs one decision rather than a thousand.
 *
 * **Reporter reputation is not touched**, which differs from a direct rejection
 * and is deliberate. Rejecting the submission in front of you is a verdict on
 * that reporter's submission. Rejecting nine hundred others because they share a
 * phrase is a verdict on the phrase, and the reporters behind them were never
 * examined. Docking nine hundred reputations from one click would let a single
 * decision silence people nobody looked at — the same failure the platform
 * already avoids by keeping the anomaly detector out of reputation.
 */
final class RejectReviewBacklogJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 200;

    public function __construct(
        private readonly int $countryId,
        private readonly string $rawText,
        private readonly string $ruledFromSubmissionId,
        private readonly string $reason,
    ) {}

    public function handle(): void
    {
        if ($this->rawText === '') {
            return;
        }

        $rejected = 0;

        Submission::query()
            ->where('country_id', $this->countryId)
            ->where('raw_text', $this->rawText)
            ->whereKeyNot($this->ruledFromSubmissionId)
            ->awaitingReview()
            ->chunkById(self::CHUNK, function ($submissions) use (&$rejected): void {
                foreach ($submissions as $submission) {
                    if ($submission->status !== Submission::STATUS_NEEDS_REVIEW) {
                        continue;
                    }

                    Resolution::query()->updateOrCreate(
                        ['submission_id' => $submission->id],
                        [
                            'canonical_item_id' => null,
                            'method' => Resolution::METHOD_RULE,
                            'confidence' => 0.0,
                            'reviewed' => false,
                            'notes' => 'Identical text to submission '.$this->ruledFromSubmissionId
                                .', which a reviewer ruled is not a product: '.$this->reason,
                        ],
                    );

                    // Any observation already derived from it is invalidated
                    // rather than deleted, the same way a direct rejection does
                    // it: the provenance chain has to survive a correction.
                    $submission->priceObservation?->forceFill(['is_valid' => false])->save();
                    $submission->forceFill(['status' => Submission::STATUS_REJECTED])->save();

                    $rejected++;
                }
            });

        if ($rejected > 0) {
            Log::info('Review backlog rejected by an identical ruling', [
                'country_id' => $this->countryId,
                'rejected' => $rejected,
            ]);
        }
    }
}
