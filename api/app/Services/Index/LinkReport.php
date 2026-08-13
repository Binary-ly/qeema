<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

/**
 * What establishing a basket's anchors did, and what it declined to do.
 *
 * The declines matter more than the successes. A location left unanchored
 * publishes no level at all, and the operator needs to know which ones and why
 * — silently anchoring on thin data would be the worse failure, so the reasons
 * are carried out rather than logged and forgotten.
 */
final class LinkReport
{
    /** @var list<array{location: string, reason: string}> */
    private array $skipped = [];

    /** @var list<string> */
    private array $anchored = [];

    private int $fallbacks = 0;

    public function __construct(
        public readonly string $method,
        public readonly ?float $countryFactor = null,
    ) {}

    public function anchored(string $location, bool $viaFallback = false): void
    {
        $this->anchored[] = $location;

        if ($viaFallback) {
            $this->fallbacks++;
        }
    }

    public function skip(string $location, string $reason): void
    {
        $this->skipped[] = ['location' => $location, 'reason' => $reason];
    }

    public function anchoredCount(): int
    {
        return count($this->anchored);
    }

    public function fallbackCount(): int
    {
        return $this->fallbacks;
    }

    /** @return list<array{location: string, reason: string}> */
    public function skips(): array
    {
        return $this->skipped;
    }

    public function isEmpty(): bool
    {
        return $this->anchored === [];
    }
}
