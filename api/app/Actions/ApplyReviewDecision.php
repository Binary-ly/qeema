<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\SubmissionNotObservable;
use App\Jobs\ClearReviewBacklogJob;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Resolution;
use App\Models\Submission;
use App\Services\Ml\MlClient;
use App\Support\Text\TextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a human reviewer's decision, and makes the system learn from it.
 *
 * The learning half is the point. A review queue that only fixes the submission
 * in front of the reviewer means the same confusing phrase arrives again
 * tomorrow, and the queue never shrinks. Every confirmed decision therefore
 * becomes a **new known variant**, so the phrase that defeated the matcher this
 * time resolves automatically next time.
 *
 * The reviewer's verdict also updates the reporter's reputation — but only a
 * *human* verdict does. Letting the automated anomaly score feed reputation
 * would let the detector progressively silence whoever it first suspected.
 */
final class ApplyReviewDecision
{
    public function __construct(
        private readonly ResolveSubmission $resolver,
        private readonly TextNormalizer $normalizer = new TextNormalizer,
    ) {}

    /**
     * Confirm a submission resolves to a canonical item.
     */
    public function approve(Submission $submission, CanonicalItem $item, ?int $reviewerId = null): Resolution
    {
        return DB::transaction(function () use ($submission, $item, $reviewerId): Resolution {
            $resolution = Resolution::query()->updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'canonical_item_id' => $item->id,
                    'method' => Resolution::METHOD_HUMAN,
                    // A human decision is certain by definition; the model's
                    // confidence in it is no longer the relevant quantity.
                    'confidence' => 1.0,
                    'reviewed' => true,
                    'reviewed_by_user_id' => $reviewerId,
                    'reviewed_at' => CarbonImmutable::now(),
                ],
            );

            $this->learnVariant($submission, $item, $reviewerId);

            // Only now does the observation exist. Creating it earlier would
            // have meant an unreviewed guess reaching the index.
            //
            // A null here means the price cannot be normalised — an unknown
            // unit, almost always. That must not pass quietly: marking the
            // submission resolved without an observation would tell the
            // reviewer they had published a price that never reached the index.
            // Throwing rolls the whole decision back, so the submission stays
            // in the queue where a human can reject it or an operator can add
            // the missing unit.
            if ($submission->priceObservation === null
                && $this->resolver->createObservation($submission, $item) === null) {
                throw new SubmissionNotObservable($submission);
            }

            $submission->forceFill(['status' => Submission::STATUS_RESOLVED])->save();

            $submission->reporter?->recordConfirmedVerdict(accepted: true);

            return $resolution;
        });
    }

    /**
     * Reject a submission outright — unusable, not merely unmatched.
     */
    public function reject(Submission $submission, string $reason, ?int $reviewerId = null): Resolution
    {
        return DB::transaction(function () use ($submission, $reason, $reviewerId): Resolution {
            $resolution = Resolution::query()->updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'canonical_item_id' => null,
                    'method' => Resolution::METHOD_HUMAN,
                    'confidence' => 0.0,
                    'reviewed' => true,
                    'reviewed_by_user_id' => $reviewerId,
                    'reviewed_at' => CarbonImmutable::now(),
                    'notes' => $reason,
                ],
            );

            // Any observation already derived from this submission is
            // invalidated rather than deleted: the provenance chain has to
            // survive a correction.
            $submission->priceObservation?->forceFill(['is_valid' => false])->save();

            $submission->forceFill(['status' => Submission::STATUS_REJECTED])->save();

            $submission->reporter?->recordConfirmedVerdict(accepted: false);

            return $resolution;
        });
    }

    /**
     * Teach the matcher the phrase that defeated it.
     *
     * Keyed on the normalised form, so two spellings that normalise identically
     * become one variant rather than two. The catalogue cache is invalidated so
     * the next match sees it immediately — a learned variant that takes ten
     * minutes to take effect would let the same phrase queue up repeatedly in
     * the meantime.
     */
    private function learnVariant(Submission $submission, CanonicalItem $item, ?int $reviewerId): void
    {
        $normalised = $this->normalizer->normalize($submission->raw_text);

        if ($normalised === '') {
            return;
        }

        $existing = CanonicalItemVariant::query()
            ->where('normalized_text', $normalised)
            ->first();

        if ($existing !== null) {
            // Already known — either for this item (the matcher simply lacked
            // confidence) or, more interestingly, for a *different* item, which
            // means the catalogue has a genuine ambiguity a reviewer should see
            // rather than have silently reassigned.
            if ($existing->canonical_item_id === $item->id) {
                $existing->recordMatch();
            }

            return;
        }

        CanonicalItemVariant::query()->create([
            'canonical_item_id' => $item->id,
            'text' => $submission->raw_text,
            'normalized_text' => $normalised,
            'locale' => preg_match('/\p{Arabic}/u', $submission->raw_text) === 1 ? 'ar' : null,
            'source' => CanonicalItemVariant::SOURCE_HUMAN_REVIEW,
            'created_from_submission_id' => $submission->id,
            'created_by_user_id' => $reviewerId,
            'times_matched' => 1,
        ]);

        MlClient::forgetCatalogue($submission->country);

        // Learning the phrase fixed the future. The backlog is the other half:
        // on the scale dataset, 367,392 queued submissions carried only 31,044
        // distinct texts, so a reviewer ruling on `أرز` was about to be shown it
        // another 1,414 times. Dispatched after the transaction commits, because
        // the job reads the variant this transaction is still writing.
        DB::afterCommit(fn () => ClearReviewBacklogJob::dispatch(
            $submission->country_id,
            $submission->raw_text,
            $item->id,
            (string) $submission->id,
        ));
    }
}
