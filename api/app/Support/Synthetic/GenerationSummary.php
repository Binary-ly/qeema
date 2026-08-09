<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Synthetic;

/**
 * What the synthetic generator produced.
 *
 * Returned rather than logged so the seeder, the bootstrap command and tests
 * can all assert on it instead of parsing console output.
 */
final readonly class GenerationSummary
{
    public function __construct(
        public int $days,
        public int $locations,
        public int $items,
        public int $submissions,
        public int $observations,
        public int $erroneous,
        public int $manipulated,
        public int $queuedForReview,
        public int $groundTruthCells,
    ) {}

    public function describe(): string
    {
        return sprintf(
            '%d days x %d locations x %d items -> %s submissions '
            .'(%s observations, %s queued for review), '
            .'%s erroneous and %s manipulated labelled, %s ground-truth rows',
            $this->days,
            $this->locations,
            $this->items,
            number_format($this->submissions),
            number_format($this->observations),
            number_format($this->queuedForReview),
            number_format($this->erroneous),
            number_format($this->manipulated),
            number_format($this->groundTruthCells),
        );
    }
}
