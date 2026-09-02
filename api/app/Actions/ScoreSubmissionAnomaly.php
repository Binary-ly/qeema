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
            // Not the row being judged.
            //
            // This was missing, and it is the whole reason a wild price scored
            // clean. The window is inclusive of the observation's own date, so
            // the price under scrutiny was inside the median it was measured
            // against — and when it was the only one in ninety days, it *was*
            // the median. `hard_bounds` then compared the price to itself,
            // found a ratio of exactly 1, and returned zero. The more absurd
            // the price, the more completely it defined its own bound.
            //
            // The neighbouring `local_prices` query has always excluded self.
            // This one did not.
            ->where('id', '!=', $observation->id)
            ->whereBetween('observed_on', [
                $observation->observed_on->copy()->subDays(self::REFERENCE_WINDOW_DAYS),
                $observation->observed_on,
            ])
            ->valid()
            ->selectRaw(
                'percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median'
            )
            ->value('median');

        // No observed history? Fall back to the catalogue's sourced price.
        //
        // This is the hole that let a kilo of wheat flour through at 10,000
        // against a catalogue that records it at 3.88. Every layer of the
        // detector is relative — bounds compare against the item's trailing
        // median, the robust test against other prices in the same town, the
        // forest against the same history — so with nothing observed they all
        // return zero, the composite is the maximum of three zeros, and a
        // submission is recorded `clean` on no evidence whatever. `clean`
        // publishes.
        //
        // That is not a corner case. It is every new deployment, every item
        // nobody has priced yet, and every deployment whose feeds have been
        // quiet for ninety days.
        //
        // Only a fallback, never an override: the moment real observations
        // exist they take over, which is what keeps the thresholds self-tuning
        // rather than pinned to a figure that ages. A sourced price is a
        // snapshot with a date on it, and an economy moves; using it as the
        // *primary* reference would start rejecting genuine prices after enough
        // inflation. Using it only when the alternative is no bound at all
        // cannot make screening worse than the silence it replaces.
        $catalogue = $observation->canonicalItem?->reference_price_per_base_unit;
        $anchor = $reference ?? $catalogue;

        return [
            'submission_id' => (string) $observation->submission_id,
            'price' => (float) $observation->normalized_price_per_base_unit,
            'local_prices' => array_values($localPrices),
            'item_reference_median' => $anchor === null ? null : (float) $anchor,
            'national_median' => $anchor === null ? null : (float) $anchor,
            'trend_expected' => $anchor === null ? null : (float) $anchor,
            // Reputation as an inverse deviation proxy: a well-regarded
            // reporter has historically deviated little.
            'reporter_mean_deviation' => 1.0 - (float) $observation->reputation_at_time,
            'reporter_submission_rate' => 1.0,
            'hour_of_day' => (int) $observation->observed_at->format('G'),
            'days_since_last_local_report' => 1.0,
        ];
    }
}
