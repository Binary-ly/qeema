<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Scraping;

use App\Models\Source;

/**
 * Contract for a price scraper.
 *
 * Scrapers are the least controllable of the three data sources: the remote end
 * can change shape, rate-limit, or simply be someone else's server that owes us
 * nothing. The contract therefore requires every implementation to be:
 *
 *   - **resumable**, via a cursor persisted on the source, so an interrupted run
 *     continues instead of restarting against a rate-limited endpoint;
 *   - **idempotent**, via a stable natural key per record, so re-running cannot
 *     double-count;
 *   - **polite**, declaring a rate limit and honouring robots.txt.
 *
 * Being a bad citizen here would be both an ethical problem and a practical one:
 * a blocked IP ends the data source permanently.
 */
interface PriceScraper
{
    /** Stable identifier, matched against `sources.config.scraper`. */
    public function key(): string;

    /** Human-readable description for the admin UI. */
    public function description(): string;

    /**
     * Maximum requests per minute this scraper will make.
     *
     * Declared by the implementation rather than configured globally, because
     * the polite rate depends on whose server it is.
     */
    public function requestsPerMinute(): int;

    /**
     * Fetch a page of records, resuming from the source's cursor.
     *
     * Returns the records found and the cursor to resume from. A null next
     * cursor means the run is complete.
     */
    public function fetch(Source $source, ?string $cursor): ScrapeResult;
}
