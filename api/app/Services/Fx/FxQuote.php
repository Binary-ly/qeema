<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx;

use DateTimeInterface;

/**
 * One exchange-rate observation.
 *
 * Both rates travel together because the *gap* between them is itself a
 * headline indicator in these economies — a platform that stored only the one
 * it converts with would throw away the more telling number.
 */
final readonly class FxQuote
{
    public function __construct(
        public DateTimeInterface $date,
        public ?float $officialRate,
        public ?float $parallelRate,
        public string $source,
        public string $baseCurrency = 'USD',
        /** @var array<string, mixed>|null */
        public ?array $raw = null,
    ) {}

    public function hasAnyRate(): bool
    {
        return $this->officialRate !== null || $this->parallelRate !== null;
    }

    /** The gap between official and parallel, as a fraction of official. */
    public function parallelPremium(): ?float
    {
        if ($this->officialRate === null || $this->parallelRate === null || $this->officialRate <= 0.0) {
            return null;
        }

        return ($this->parallelRate - $this->officialRate) / $this->officialRate;
    }
}
