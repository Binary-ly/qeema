<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\PriceObservation;
use App\Models\Reporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Estimates one item's price in one place on one day.
 *
 * A **weighted median**, not a mean. Crisis-market price data is heavy-tailed
 * and contains data-entry errors that survive anomaly screening; a mean is
 * dragged by exactly the values that should count least. The median is
 * indifferent to how wrong an outlier is, only to how many there are.
 *
 * Weights combine two things:
 *
 * - **recency**, decaying exponentially, because a price from six days ago is
 *   weaker evidence about today than one from this morning;
 * - **reporter reputation as frozen at ingestion**, so that recomputing an old
 *   snapshot cannot drift because someone's score changed since.
 *
 * That second point is what makes recomputation deterministic, and it is the
 * reason `reputation_at_time` is stored on the observation rather than joined
 * from the reporter.
 */
final class PriceEstimator
{
    /**
     * Estimate a price from observations, or null when there are none.
     *
     * @param  Collection<int, PriceObservation>  $observations
     */
    public function estimate(
        Collection $observations,
        CarbonImmutable $asOf,
        float $halfLifeDays,
    ): ?PriceEstimate {
        if ($observations->isEmpty()) {
            return null;
        }

        $weighted = $observations
            ->map(fn (PriceObservation $o): array => [
                'value' => (float) $o->normalized_price_per_base_unit,
                'weight' => $o->estimatorWeight($asOf, $halfLifeDays),
                'id' => $o->id,
            ])
            ->filter(fn (array $row): bool => $row['weight'] > 0.0 && $row['value'] > 0.0)
            ->values();

        if ($weighted->isEmpty()) {
            return null;
        }

        return new PriceEstimate(
            value: self::weightedMedian(
                $weighted->pluck('value')->all(),
                $weighted->pluck('weight')->all(),
            ),
            observationCount: $weighted->count(),
            observationIds: $weighted->pluck('id')->all(),
            values: $weighted->pluck('value')->all(),
            weights: $weighted->pluck('weight')->all(),
        );
    }

    /**
     * Weighted median of a set of values.
     *
     * The value at which cumulative weight first reaches half the total. When
     * that point falls exactly between two values the midpoint is taken, which
     * matters for the two-observation case that is common in thin coverage.
     *
     * @param  list<float>  $values
     * @param  list<float>  $weights
     */
    public static function weightedMedian(array $values, array $weights): float
    {
        $pairs = array_map(
            static fn (float $v, float $w): array => ['value' => $v, 'weight' => $w],
            $values,
            $weights,
        );

        usort($pairs, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        $total = array_sum(array_column($pairs, 'weight'));

        if ($total <= 0.0) {
            return 0.0;
        }

        $half = $total / 2.0;
        $cumulative = 0.0;

        foreach ($pairs as $i => $pair) {
            $cumulative += $pair['weight'];

            if ($cumulative > $half) {
                return $pair['value'];
            }

            // Exactly half: the median sits between this value and the next.
            if (abs($cumulative - $half) < 1e-12 && isset($pairs[$i + 1])) {
                return ($pair['value'] + $pairs[$i + 1]['value']) / 2.0;
            }
        }

        return (float) end($pairs)['value'];
    }

    /**
     * Resample an estimate to characterise its sampling uncertainty.
     *
     * A weighted bootstrap: draw observations with replacement in proportion to
     * their weights and recompute the median. This says how much the estimate
     * would move if a different handful of shops had been visited, which is a
     * real and usually large source of uncertainty when a price rests on three
     * observations.
     *
     * @return list<float>
     */
    public function bootstrap(PriceEstimate $estimate, int $draws, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $n = count($estimate->values);
        $samples = [];

        // Cumulative weights, so a draw is a single binary search rather than a
        // scan per observation.
        $cumulative = [];
        $running = 0.0;
        foreach ($estimate->weights as $weight) {
            $running += $weight;
            $cumulative[] = $running;
        }

        if ($running <= 0.0) {
            return [];
        }

        for ($draw = 0; $draw < $draws; $draw++) {
            $values = [];
            $weights = [];

            for ($i = 0; $i < $n; $i++) {
                $target = $randomizer->getFloat(0.0, $running);
                $index = 0;

                foreach ($cumulative as $j => $bound) {
                    if ($target <= $bound) {
                        $index = $j;

                        break;
                    }
                }

                $values[] = $estimate->values[$index];
                $weights[] = $estimate->weights[$index];
            }

            $samples[] = self::weightedMedian($values, $weights);
        }

        return $samples;
    }

    /** Floor applied to reputation when weighting; re-exported for clarity. */
    public static function reputationFloor(): float
    {
        return Reporter::WEIGHT_FLOOR;
    }
}
