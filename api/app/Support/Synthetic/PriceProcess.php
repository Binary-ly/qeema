<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Synthetic;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The "true" price of an item in a location on a day.
 *
 * This is the ground truth the whole demo rests on: the generator samples noisy
 * observations from it, and Phases 6 and 8 are scored against it. It is a pure
 * function of (item, location, day) given a seed — no database, no clock, no
 * global state — so the same seed produces byte-identical data on any machine,
 * which is what makes the demo reproducible and the evaluation numbers
 * comparable across runs.
 *
 * The model is deliberately simple but not naive. It reproduces the four things
 * that actually make crisis-economy prices hard to model:
 *
 *   1. persistent inflation, compounding rather than linear;
 *   2. a currency that moves, passing through to imported goods with a lag;
 *   3. geography — periphery costs more than the capital, structurally;
 *   4. shocks that arrive suddenly and decay slowly.
 */
final class PriceProcess
{
    /**
     * Share of an item's price that tracks the exchange rate.
     *
     * Infant formula and paediatric medicines are almost entirely imported, so
     * a devaluation reaches their shelf price quickly. Locally grown produce
     * barely moves with the currency. Getting this wrong would make the FX
     * signal either invisible or overwhelming.
     */
    private const IMPORT_INTENSITY = [
        'infant_nutrition' => 0.85,
        'medicine' => 0.90,
        'staples' => 0.55,
        'school' => 0.60,
        'hygiene' => 0.70,
        'fuel' => 0.30,
        'protein' => 0.35,
        'produce' => 0.10,
        'water' => 0.15,
    ];

    /** Days for an exchange-rate move to reach the shelf. */
    private const FX_PASSTHROUGH_LAG_DAYS = 21;

    private readonly Randomizer $randomizer;

    /** @var array<string, float> per-(location,item) persistent level offset */
    private array $levelOffsets = [];

    /** @var list<array{location: string, item: string, start: int, size: float, decay: float}> */
    private array $shocks = [];

    /**
     * @param  array<string, float>  $referencePrices  item code => price at day 0
     * @param  array<string, float>  $regionalPremium  location slug => multiplier
     * @param  array<string, string>  $itemCategories  item code => category
     */
    public function __construct(
        private readonly array $referencePrices,
        private readonly array $regionalPremium,
        private readonly array $itemCategories,
        private readonly float $monthlyInflation,
        private readonly int $seed,
    ) {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * Precompute per-series offsets and shocks.
     *
     * Done once rather than per-day so a series stays internally consistent: a
     * location that is dear for an item stays dear, rather than flickering.
     *
     * @param  list<string>  $locationSlugs
     * @param  list<string>  $itemCodes
     */
    public function prepare(array $locationSlugs, array $itemCodes, int $days): void
    {
        foreach ($locationSlugs as $location) {
            foreach ($itemCodes as $item) {
                // A persistent ±8% level difference: shops differ, and a model
                // where every location sits exactly on the national mean makes
                // spatial imputation look far easier than it is.
                $this->levelOffsets[$location.'|'.$item] =
                    1.0 + ($this->randomizer->getFloat(-0.08, 0.08));
            }
        }

        // Roughly one supply shock per location per two months. These are what
        // make the anomaly detector's job hard: a genuine 40% jump must not be
        // discarded as an outlier.
        $expectedShocks = (int) round(count($locationSlugs) * ($days / 60.0));

        for ($i = 0; $i < $expectedShocks; $i++) {
            $this->shocks[] = [
                'location' => $locationSlugs[$this->randomizer->getInt(0, count($locationSlugs) - 1)],
                'item' => $itemCodes[$this->randomizer->getInt(0, count($itemCodes) - 1)],
                'start' => $this->randomizer->getInt(0, max(0, $days - 1)),
                'size' => $this->randomizer->getFloat(0.15, 0.60),
                'decay' => $this->randomizer->getFloat(0.05, 0.15),
            ];
        }
    }

    /**
     * True price per base unit.
     *
     * @param  int  $day  days since the start of the series
     * @param  float  $fxIndex  parallel rate on this day, relative to day 0
     * @param  float  $fxIndexLagged  parallel rate FX_PASSTHROUGH_LAG_DAYS earlier
     */
    public function truePrice(
        string $locationSlug,
        string $itemCode,
        int $day,
        float $fxIndex,
        float $fxIndexLagged,
    ): float {
        $base = $this->referencePrices[$itemCode] ?? 1.0;

        $inflation = (1.0 + $this->monthlyInflation) ** ($day / 30.0);
        $regional = $this->regionalPremium[$locationSlug] ?? 1.0;
        $level = $this->levelOffsets[$locationSlug.'|'.$itemCode] ?? 1.0;

        $price = $base * $inflation * $regional * $level
            * $this->fxFactor($itemCode, $fxIndex, $fxIndexLagged)
            * $this->seasonalFactor($itemCode, $day)
            * $this->shockFactor($locationSlug, $itemCode, $day);

        return round($price, 4);
    }

    /**
     * Exchange-rate pass-through, weighted by how imported the item is.
     *
     * The lagged rate does most of the work: shelf prices respond to a
     * devaluation over weeks, not the same afternoon, and a model without that
     * lag makes the FX feature trivially predictive.
     */
    private function fxFactor(string $itemCode, float $fxIndex, float $fxIndexLagged): float
    {
        $category = $this->itemCategories[$itemCode] ?? 'staples';
        $intensity = self::IMPORT_INTENSITY[$category] ?? 0.5;

        $effectiveFx = 0.25 * $fxIndex + 0.75 * $fxIndexLagged;

        return 1.0 + $intensity * ($effectiveFx - 1.0);
    }

    /**
     * Seasonal demand.
     *
     * Two effects that matter for a child-weighted basket specifically: food
     * demand rises through Ramadan, and school materials spike as term starts.
     * A general CPI would smooth both away; this basket should not.
     *
     * Day 0 of the series is treated as 1 January for phase purposes.
     */
    private function seasonalFactor(string $itemCode, int $day): float
    {
        $category = $this->itemCategories[$itemCode] ?? 'staples';

        $factor = 1.0;

        // Ramadan-like food demand window. Approximated as a fixed window
        // rather than a true lunar calendar: the demo needs the shape, and a
        // real deployment would drive this from the country configuration.
        $ramadanStart = 45;
        $ramadanEnd = 75;
        if ($day >= $ramadanStart && $day <= $ramadanEnd
            && in_array($category, ['staples', 'protein', 'produce'], true)) {
            $progress = ($day - $ramadanStart) / ($ramadanEnd - $ramadanStart);
            $factor *= 1.0 + 0.12 * sin(M_PI * $progress);
        }

        // School term start.
        if ($category === 'school') {
            $termStart = 240;
            if ($day >= $termStart - 21 && $day <= $termStart + 14) {
                $factor *= 1.18;
            }
        }

        // Cooking fuel is dearer in winter.
        if ($category === 'fuel') {
            $factor *= 1.0 + 0.07 * cos(2 * M_PI * $day / 365.0);
        }

        return $factor;
    }

    /** Multiplicative supply shock decaying exponentially from its start day. */
    private function shockFactor(string $locationSlug, string $itemCode, int $day): float
    {
        $factor = 1.0;

        foreach ($this->shocks as $shock) {
            if ($shock['location'] !== $locationSlug || $shock['item'] !== $itemCode) {
                continue;
            }

            if ($day < $shock['start']) {
                continue;
            }

            $age = $day - $shock['start'];
            $factor *= 1.0 + $shock['size'] * exp(-$shock['decay'] * $age);
        }

        return $factor;
    }

    /**
     * Observation noise around the true price.
     *
     * Shops differ, reporters round, and the same item is not the same price on
     * both sides of a town. Without this the estimator would look far more
     * accurate than it could ever be in reality.
     */
    public function observedPrice(float $truePrice, float $dispersion = 0.06): float
    {
        $noise = 1.0 + $this->randomizer->getFloat(-$dispersion, $dispersion);

        return round(max(0.01, $truePrice * $noise), 4);
    }

    public function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    /**
     * Exchange-rate path.
     *
     * A random walk with upward drift and occasional step devaluations, which
     * is what a managed-but-failing peg actually looks like. Returned as an
     * index relative to day 0 so items can scale by it directly.
     *
     * @return list<float>
     */
    public function fxPath(int $days, float $monthlyDrift = 0.018): array
    {
        $path = [1.0];

        for ($day = 1; $day < $days; $day++) {
            $drift = (1.0 + $monthlyDrift) ** (1 / 30.0);
            $noise = 1.0 + $this->randomizer->getFloat(-0.004, 0.004);

            $next = $path[$day - 1] * $drift * $noise;

            // Roughly one step devaluation per quarter.
            if ($this->randomizer->getInt(1, 90) === 1) {
                $next *= 1.0 + $this->randomizer->getFloat(0.03, 0.09);
            }

            $path[] = round($next, 6);
        }

        return $path;
    }

    /**
     * The value of a path `lag` days before `day`, clamped at the start.
     *
     * @param  list<float>  $path
     */
    public static function lagged(array $path, int $day, int $lag = self::FX_PASSTHROUGH_LAG_DAYS): float
    {
        return $path[max(0, $day - $lag)] ?? 1.0;
    }
}
