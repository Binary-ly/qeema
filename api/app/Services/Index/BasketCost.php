<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

/**
 * What a basket costs at one location on one day, before anything is stored.
 *
 * This exists because chain-linking has to cost the *new* basket on a day the
 * *old* one was in force, and doing that through `calculate()` would write a
 * snapshot for a basket that was not in force on that date — publishing a figure
 * nobody asked for and that the series should not contain (D-22).
 *
 * So the calculator now computes into this and persists separately. The useful
 * side effect is that the arithmetic can be tested without touching the
 * database.
 */
final readonly class BasketCost
{
    /**
     * @param  list<array<string, mixed>>  $itemRows
     */
    public function __construct(
        public float $costLocal,
        public float $coveragePct,
        public float $imputedShare,
        public ?float $ciLow,
        public ?float $ciHigh,
        public int $observedItemCount,
        public int $totalItemCount,
        public array $itemRows,
    ) {}

    /**
     * Every item in the basket has a price, whether observed or imputed.
     *
     * The same bar `IndexSnapshot::isComparable()` sets, and for the same
     * reason: a cost covering part of a basket is not the cost of that basket.
     * Chain-linking refuses to anchor on anything less (D-20), because a
     * coverage artefact folded into a reference cost never washes out — it
     * shifts every level computed from that anchor for as long as the basket
     * version lives.
     */
    public function isComplete(): bool
    {
        return ($this->coveragePct + $this->imputedShare) >= 0.999;
    }

    /** Nothing in the basket could be priced at all. */
    public function isEmpty(): bool
    {
        return $this->itemRows === [];
    }
}
