<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Scraping;

/**
 * One page of scraped records.
 *
 * @phpstan-type ScrapedRecord array{
 *     external_id: string,
 *     item_text: string,
 *     price: float,
 *     location: string,
 *     unit?: string|null,
 *     quantity?: float|null,
 *     currency?: string|null,
 *     observed_at?: string|null
 * }
 */
final readonly class ScrapeResult
{
    /**
     * @param  list<ScrapedRecord>  $records
     * @param  list<string>  $warnings  non-fatal problems worth surfacing
     */
    public function __construct(
        public array $records,
        public ?string $nextCursor = null,
        public array $warnings = [],
    ) {}

    public function isComplete(): bool
    {
        return $this->nextCursor === null;
    }

    public static function empty(): self
    {
        return new self([], null);
    }
}
