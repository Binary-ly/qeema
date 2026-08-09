<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Scraping;

use RuntimeException;

/**
 * Registry of available scrapers.
 *
 * A source names its scraper by key in configuration, so adding a new one means
 * writing a class and registering it here — not editing an ingestion pipeline
 * that already works.
 */
final class ScraperRegistry
{
    /** @var array<string, PriceScraper> */
    private array $scrapers = [];

    public function register(PriceScraper $scraper): void
    {
        $this->scrapers[$scraper->key()] = $scraper;
    }

    public function get(string $key): PriceScraper
    {
        return $this->scrapers[$key]
            ?? throw new RuntimeException(sprintf(
                "Unknown scraper '%s'. Registered: %s",
                $key,
                $this->keys() === [] ? '(none)' : implode(', ', $this->keys()),
            ));
    }

    public function has(string $key): bool
    {
        return isset($this->scrapers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->scrapers);
    }

    /**
     * @return array<string, PriceScraper>
     */
    public function all(): array
    {
        return $this->scrapers;
    }
}
