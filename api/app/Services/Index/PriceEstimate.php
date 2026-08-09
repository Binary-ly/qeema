<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

/**
 * One item's estimated price, with the evidence behind it.
 *
 * The raw values and weights are carried, not just the result, because the
 * confidence interval is computed by resampling them. An estimate that cannot
 * say how uncertain it is has no business appearing in a published figure.
 */
final readonly class PriceEstimate
{
    /**
     * @param  list<int>  $observationIds
     * @param  list<float>  $values
     * @param  list<float>  $weights
     */
    public function __construct(
        public float $value,
        public int $observationCount,
        public array $observationIds,
        public array $values,
        public array $weights,
    ) {}
}
