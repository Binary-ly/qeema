<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\CountryConfig;

/**
 * What a country configuration import actually did.
 *
 * Returned rather than logged so callers — the seeder, the bootstrap command
 * and tests — can assert on it instead of parsing console output.
 */
final readonly class ImportSummary
{
    public function __construct(
        public string $countryCode,
        public int $units,
        public int $locations,
        public int $canonicalItems,
        public int $variants,
        public int $basketItems,
        public int $sources,
    ) {}

    public function describe(): string
    {
        return sprintf(
            '%s: %d units, %d locations, %d items (%d new variants), %d basket entries, %d sources',
            $this->countryCode,
            $this->units,
            $this->locations,
            $this->canonicalItems,
            $this->variants,
            $this->basketItems,
            $this->sources,
        );
    }
}
