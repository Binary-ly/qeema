<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions;

use App\Models\CanonicalItem;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Submission;
use App\Models\Unit;
use App\Models\UnmatchablePhrase;
use App\Services\Ml\MatchResult;
use App\Services\Ml\MlClientInterface;
use App\Support\Text\TextNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a raw submission into a validated price observation.
 *
 * This is the step where the platform's central promise is either kept or
 * quietly broken. Everything downstream — the index, the dashboard, the public
 * API — trusts that a price observation means "somebody reported this price,
 * for this item, and we are confident enough about which item it was".
 *
 * So the failure mode this class is built around is *not* being wrong. It is
 * being wrong **silently**. When the matcher is unsure, when the ML service is
 * unreachable, or when the unit cannot be normalised, the submission goes to a
 * human. It never becomes an observation on a guess.
 */
final class ResolveSubmission
{
    public function __construct(
        private readonly MlClientInterface $ml,
        private readonly TextNormalizer $normalizer = new TextNormalizer,
    ) {}

    public function handle(Submission $submission): Resolution
    {
        // A reviewer has already ruled on this exact phrase. Checked before the
        // matcher runs, for the same reason the catalogue's exact map is: a
        // decision a human has already made must not be re-asked of the model,
        // and must not be re-asked of another human either. This is the mirror
        // of a learned variant — one teaches what a phrase means, the other
        // that it means nothing.
        if (($ruling = $this->ruledUnmatchable($submission)) !== null) {
            return $this->recordUnmatchable($submission, $ruling);
        }

        $result = $this->ml->match($submission->country, $submission->raw_text);

        // Null means the service had no opinion — unreachable, errored, or
        // circuit open. That is emphatically not "no match": discarding a valid
        // observation because a container was restarting would be a silent data
        // loss with no trace.
        if ($result === null) {
            return $this->routeToReview(
                $submission,
                null,
                'Matching service unavailable; queued for human review.',
                Resolution::METHOD_RULE,
            );
        }

        if (! $result->shouldAutoResolve()) {
            return $this->routeToReview(
                $submission,
                $result,
                $result->reason,
                Resolution::METHOD_FUSED,
            );
        }

        $item = CanonicalItem::query()
            ->where('country_id', $submission->country_id)
            ->find($result->bestItemId());

        if ($item === null) {
            // The service named an item this deployment does not have — a stale
            // catalogue cache, or a country mix-up. Never guess past it, and
            // never write the unknown id: `resolutions.canonical_item_id` is a
            // foreign key, so storing it would turn a recoverable mismatch into
            // a 500.
            return $this->routeToReview(
                $submission,
                $result,
                'Matched item is not in this country catalogue.',
                Resolution::METHOD_FUSED,
                itemId: null,
            );
        }

        return DB::transaction(function () use ($submission, $result, $item): Resolution {
            $resolution = $this->recordResolution(
                $submission,
                $item->id,
                Resolution::METHOD_FUSED,
                $result->confidence(),
                $result,
                reviewed: false,
            );

            $observation = $this->createObservation($submission, $item);

            if ($observation === null) {
                // Normalisation failed — an unknown unit, most likely. The
                // match may be right but the number is not usable, and a price
                // we cannot express per base unit must not reach the index.
                $submission->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW])->save();

                return $resolution;
            }

            $submission->forceFill(['status' => Submission::STATUS_RESOLVED])->save();

            return $resolution;
        });
    }

    /**
     * Has a reviewer ruled this exact phrase is not a product?
     *
     * Scoped to the country, because a phrase that is noise in one deployment
     * may be a product in another.
     */
    private function ruledUnmatchable(Submission $submission): ?UnmatchablePhrase
    {
        $normalised = $this->normalizer->normalize($submission->raw_text);

        if ($normalised === '') {
            return null;
        }

        return UnmatchablePhrase::query()
            ->forCountry($submission->country_id)
            ->where('normalized_text', $normalised)
            ->first();
    }

    /**
     * Reject without asking a human, and record why.
     *
     * The submission is marked rejected rather than deleted, and the resolution
     * names the ruling that caused it, so a price discarded this way is
     * traceable to the decision that discarded it and recoverable if that
     * decision was wrong.
     */
    private function recordUnmatchable(Submission $submission, UnmatchablePhrase $phrase): Resolution
    {
        return DB::transaction(function () use ($submission, $phrase): Resolution {
            $phrase->recordMatch();

            $resolution = Resolution::query()->updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'canonical_item_id' => null,
                    'method' => Resolution::METHOD_RULE,
                    'confidence' => 0.0,
                    'reviewed' => false,
                    'notes' => 'A reviewer ruled this phrase is not a product tracked here'
                        .($phrase->reason === null ? '.' : ': '.$phrase->reason),
                ],
            );

            $submission->forceFill(['status' => Submission::STATUS_REJECTED])->save();

            return $resolution;
        });
    }

    /**
     * Build the price observation, normalised to a price per base unit.
     *
     * Returns null when the unit is unknown rather than assuming one. Guessing
     * a unit is how a price per gram becomes a price per kilo and moves a
     * published figure by three orders of magnitude.
     */
    public function createObservation(Submission $submission, CanonicalItem $item): ?PriceObservation
    {
        $unitCode = $this->resolveUnitCode($submission, $item);

        $unit = Unit::query()
            ->where('country_id', $submission->country_id)
            ->where('code', $unitCode)
            ->first();

        if ($unit === null) {
            return null;
        }

        $quantity = (float) ($submission->raw_quantity ?? $item->default_quantity ?? 1.0);

        if ($quantity <= 0.0) {
            return null;
        }

        try {
            $perBaseUnit = $unit->pricePerBaseUnit((float) $submission->raw_price, $quantity);
        } catch (InvalidArgumentException) {
            return null;
        }

        return PriceObservation::query()->create([
            'submission_id' => $submission->id,
            'country_id' => $submission->country_id,
            'location_id' => $submission->location_id,
            'canonical_item_id' => $item->id,
            'price' => $submission->raw_price,
            'currency_code' => $submission->currency_code,
            'unit_code' => $unit->code,
            'quantity' => $quantity,
            'normalized_price_per_base_unit' => $perBaseUnit,
            'observed_on' => $submission->observed_at?->toDateString(),
            'observed_at' => $submission->observed_at,
            'reporter_id' => $submission->reporter_id,
            'source_id' => $submission->source_id,
            // Frozen at ingestion so recomputing an old snapshot is
            // deterministic rather than drifting with the reporter's score.
            'reputation_at_time' => $submission->reporter === null ? 0.5 : $submission->reporter->reputation,
            // What this price actually counted for, which is the posterior's
            // lower bound rather than its mean — an estimate backed by four
            // observations should not weigh as much as one backed by four
            // hundred, and a freshly minted identity should not be worth twice
            // the floor its predecessor was sitting on.
            'weight_at_time' => $submission->reporter?->weight() ?? Reporter::WEIGHT_FLOOR,
            'is_valid' => true,
        ]);
    }

    /**
     * Decide which unit a submission is priced in.
     *
     * The reporter's own unit text wins when it maps to a configured unit,
     * because they were looking at the shelf. The item default is a fallback,
     * not an override.
     */
    private function resolveUnitCode(Submission $submission, CanonicalItem $item): string
    {
        $raw = mb_strtolower(trim((string) $submission->raw_unit));

        if ($raw === '') {
            return $item->default_unit_code;
        }

        $exists = Unit::query()
            ->where('country_id', $submission->country_id)
            ->where('code', $raw)
            ->exists();

        return $exists ? $raw : $item->default_unit_code;
    }

    private function routeToReview(
        Submission $submission,
        ?MatchResult $result,
        string $reason,
        string $method,
        ?int $itemId = -1,
    ): Resolution {
        // -1 is the "use the match's own suggestion" sentinel; an explicit null
        // means the suggestion is not storable.
        $suggested = $itemId === -1 ? $result?->bestItemId() : $itemId;

        return DB::transaction(function () use ($submission, $result, $reason, $method, $suggested): Resolution {
            $resolution = $this->recordResolution(
                $submission,
                $suggested,
                $method,
                $result?->confidence(),
                $result,
                reviewed: false,
                reason: $reason,
            );

            $submission->forceFill(['status' => Submission::STATUS_NEEDS_REVIEW])->save();

            return $resolution;
        });
    }

    private function recordResolution(
        Submission $submission,
        ?int $canonicalItemId,
        string $method,
        ?float $confidence,
        ?MatchResult $result,
        bool $reviewed,
        ?string $reason = null,
    ): Resolution {
        return Resolution::query()->updateOrCreate(
            ['submission_id' => $submission->id],
            [
                'canonical_item_id' => $canonicalItemId,
                'method' => $method,
                'confidence' => $confidence,
                // The full candidate list is kept, not just the winner. When a
                // human later overrules the match, this is what says whether
                // the right answer was ranked second or was never retrieved —
                // a ranking problem and a recall problem need different fixes.
                'candidates' => $result?->candidates,
                'reviewed' => $reviewed,
                'model_version' => $result?->modelVersion,
                'notes' => $reason,
            ],
        );
    }
}
