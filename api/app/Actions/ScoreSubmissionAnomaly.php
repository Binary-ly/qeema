<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions;

use App\Models\AnomalyScore;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Services\Ml\MlClientInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores resolved submissions for anomalies and records the verdict.
 *
 * Runs *after* resolution, because the useful comparison is against other
 * prices for the same item in the same place — which requires knowing which
 * item it is. Scoring raw text against nothing would be guesswork.
 *
 * A rejected verdict invalidates the observation rather than deleting it. The
 * provenance chain has to survive a machine decision exactly as it survives a
 * human one, and an operator overruling the detector needs the original row to
 * still be there.
 */
final class ScoreSubmissionAnomaly
{
    /** Days of history compared against when judging a price. */
    private const CONTEXT_WINDOW_DAYS = 7;

    /** Days of history used to derive an item's plausible range. */
    private const REFERENCE_WINDOW_DAYS = 90;

    public function __construct(private readonly MlClientInterface $ml) {}

    /**
     * Score a batch of observations.
     *
     * @param  Collection<int, PriceObservation>  $observations
     * @return int number of verdicts recorded
     */
    public function handle(Collection $observations): int
    {
        if ($observations->isEmpty()) {
            return 0;
        }

        $payload = $observations
            ->map(fn (PriceObservation $o): array => $this->contextFor($o))
            ->values()
            ->all();

        $verdicts = $this->ml->scoreAnomalies($payload);

        // Null means the service had no opinion. An unscored submission is one
        // nobody has judged yet — recording a clean verdict would let bad data
        // through precisely when the system is least able to notice.
        if ($verdicts === null) {
            return 0;
        }

        $byId = $observations->keyBy(fn (PriceObservation $o): string => (string) $o->submission_id);
        $recorded = 0;

        foreach ($verdicts as $verdict) {
            $observation = $byId->get((string) ($verdict['submission_id'] ?? ''));

            if ($observation === null) {
                continue;
            }

            $this->record($observation, $verdict);
            $recorded++;
        }

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $verdict
     */
    private function record(PriceObservation $observation, array $verdict): void
    {
        DB::transaction(function () use ($observation, $verdict): void {
            AnomalyScore::query()->create([
                'submission_id' => $observation->submission_id,
                'score' => (float) ($verdict['score'] ?? 0.0),
                'verdict' => (string) ($verdict['verdict'] ?? AnomalyScore::VERDICT_CLEAN),
                'reasons' => $verdict['reasons'] ?? [],
                'layer_scores' => $verdict['layer_scores'] ?? null,
                'model_version' => $verdict['model_version'] ?? null,
            ]);

            $outcome = (string) ($verdict['verdict'] ?? AnomalyScore::VERDICT_CLEAN);

            if ($outcome === AnomalyScore::VERDICT_REJECTED) {
                // Invalidated, not deleted: an operator overruling the detector
                // needs the original row to still exist.
                $observation->forceFill(['is_valid' => false])->save();
                $observation->submission?->forceFill([
                    'status' => Submission::STATUS_NEEDS_REVIEW,
                ])->save();

                return;
            }

            if ($outcome === AnomalyScore::VERDICT_SUSPECT) {
                // Suspect keeps the observation valid but asks a human to look.
                // Discarding on suspicion alone would silently drop genuine
                // supply shocks, which are exactly what this platform exists to
                // publish.
                $observation->submission?->forceFill([
                    'status' => Submission::STATUS_NEEDS_REVIEW,
                ])->save();
            }
        });
    }

    /**
     * Assemble the context the detector needs to judge one price.
     *
     * @return array<string, mixed>
     */
    private function contextFor(PriceObservation $observation): array
    {
        $localPrices = PriceObservation::query()
            ->where('location_id', $observation->location_id)
            ->where('canonical_item_id', $observation->canonical_item_id)
            ->where('id', '!=', $observation->id)
            ->whereBetween('observed_on', [
                $observation->observed_on->copy()->subDays(self::CONTEXT_WINDOW_DAYS),
                $observation->observed_on,
            ])
            ->valid()
            ->pluck('normalized_price_per_base_unit')
            ->map(fn ($p): float => (float) $p)
            ->all();

        // percentile_cont rather than an average: the reference is used to
        // bound plausible prices, and an average would be dragged upward by the
        // very outliers those bounds exist to catch.
        $reference = PriceObservation::query()
            ->where('canonical_item_id', $observation->canonical_item_id)
            ->whereBetween('observed_on', [
                $observation->observed_on->copy()->subDays(self::REFERENCE_WINDOW_DAYS),
                $observation->observed_on,
            ])
            ->valid()
            ->selectRaw(
                'percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median'
            )
            ->value('median');

        return [
            'submission_id' => (string) $observation->submission_id,
            'price' => (float) $observation->normalized_price_per_base_unit,
            'local_prices' => array_values($localPrices),
            'item_reference_median' => $reference === null ? null : (float) $reference,
            'national_median' => $reference === null ? null : (float) $reference,
            'trend_expected' => $reference === null ? null : (float) $reference,
            // Reputation as an inverse deviation proxy: a well-regarded
            // reporter has historically deviated little.
            'reporter_mean_deviation' => 1.0 - (float) $observation->reputation_at_time,
            'reporter_submission_rate' => 1.0,
            'hour_of_day' => (int) $observation->observed_at->format('G'),
            'days_since_last_local_report' => 1.0,
        ];
    }
}
