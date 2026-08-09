<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx;

use Carbon\CarbonImmutable;

/**
 * The rate a snapshot was converted at, with its provenance.
 *
 * `isStale` and `ageDays` travel with the rate rather than being recomputed
 * later, so a published figure can always say how old the conversion behind it
 * was. A USD number whose staleness is not knowable is a number nobody can
 * audit.
 */
final readonly class ResolvedRate
{
    public function __construct(
        public float $rate,
        public string $type,
        public CarbonImmutable $rateDate,
        public bool $isStale,
        public int $ageDays,
    ) {}
}
