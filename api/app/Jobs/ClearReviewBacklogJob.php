<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ResolveSubmission;
use App\Models\CanonicalItem;
use App\Models\Resolution;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies one reviewer's decision to every identical submission already queued.
 *
 * **The problem, measured.** On the 3.2 million-row dataset, 367,392 submissions
 * were awaiting review and they carried only **31,044 distinct texts** — a
 * duplication factor of 11.8, with the commonest phrase (`أرز`) appearing 1,415
 * times. A reviewer working that queue would rule on `أرز` and then be shown it
 * again 1,414 times.
 *
 * `ApplyReviewDecision::learnVariant` already fixes the *future*: the phrase
 * becomes a catalogue variant, so the next submission carrying it resolves on
 * its own. It did nothing about the backlog. Every row already in the queue
 * stayed there, resolvable by a matcher that would now get it right, waiting for
 * a human to repeat a decision that had just been made.
 *
 * **Provenance is `exact`, deliberately.** Not `human`: a reviewer never saw
 * these rows and must not appear to have approved their prices. Not `fused`: no
 * model ran. What actually happened is that the text matched a known variant
 * exactly, which is what `METHOD_EXACT` means, and the note records which
 * decision taught it.
 *
 * **Reporter reputation is left alone** for the same reason. The reviewer
 * confirmed what the *phrase* means, not that each of these prices is honest.
 * Crediting a thousand reporters for one human decision would let a single
 * approval move reputations the reviewer never looked at.
 */
final class ClearReviewBacklogJob implements ShouldQueue
{
    use Queueable;

    /** Rows per chunk. Small enough that a retry repeats little work. */
    private const CHUNK = 200;

    public function __construct(
        private readonly int $countryId,
        private readonly string $rawText,
        private readonly int $canonicalItemId,
        private readonly string $learnedFromSubmissionId,
    ) {}

    public function handle(ResolveSubmission $resolver): void
    {
        $item = CanonicalItem::query()->find($this->canonicalItemId);

        if ($item === null || $this->rawText === '') {
            return;
        }

        $cleared = 0;
        $unobservable = 0;

        Submission::query()
            ->where('country_id', $this->countryId)
            ->where('raw_text', $this->rawText)
            ->whereKeyNot($this->learnedFromSubmissionId)
            ->awaitingReview()
            ->chunkById(self::CHUNK, function ($submissions) use ($item, $resolver, &$cleared, &$unobservable): void {
                foreach ($submissions as $submission) {
                    $this->clear($submission, $item, $resolver) ? $cleared++ : $unobservable++;
                }
            });

        if ($cleared > 0 || $unobservable > 0) {
            Log::info('Review backlog cleared by an identical decision', [
                'country_id' => $this->countryId,
                'canonical_item_id' => $this->canonicalItemId,
                'cleared' => $cleared,
                'left_for_review' => $unobservable,
            ]);
        }
    }

    /**
     * Resolve one sibling, or leave it where it is.
     *
     * Idempotent: a submission that has already left the queue is skipped, so a
     * retry or a double dispatch costs a query and changes nothing.
     */
    private function clear(Submission $submission, CanonicalItem $item, ResolveSubmission $resolver): bool
    {
        if ($submission->status !== Submission::STATUS_NEEDS_REVIEW) {
            return false;
        }

        $observation = $submission->priceObservation
            ?? $resolver->createObservation($submission, $item);

        if ($observation === null) {
            // The mapping is right but the number cannot be expressed per base
            // unit — an unknown unit, almost always. That is a different
            // question from what the phrase means, and it still needs a human,
            // so this row stays in the queue rather than being quietly resolved.
            return false;
        }

        Resolution::query()->updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'canonical_item_id' => $item->id,
                'method' => Resolution::METHOD_EXACT,
                'confidence' => 1.0,
                'reviewed' => false,
                'notes' => 'Identical text to submission '.$this->learnedFromSubmissionId.', which a reviewer resolved.',
            ],
        );

        $submission->forceFill(['status' => Submission::STATUS_RESOLVED])->save();

        return true;
    }
}
