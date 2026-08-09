<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Ml;

use App\Models\Country;

/**
 * The Laravel side of the ML boundary.
 *
 * Callers depend on this rather than on the HTTP client, so a test double
 * stands in as a peer implementation rather than by subclassing and overriding
 * — which would leave real network code one forgotten override away from
 * running inside a test.
 *
 * **Every method returns null rather than throwing when the service is
 * unavailable.** Null means "no opinion", which callers turn into a
 * review-queue entry. It never means "no match": discarding a valid observation
 * because a container was restarting would be silent data loss.
 */
interface MlClientInterface
{
    public function isAvailable(): bool;

    public function match(Country $country, string $text, ?int $topK = null): ?MatchResult;

    /**
     * @param  list<string>  $texts
     * @return array<int, MatchResult>|null
     */
    public function matchBatch(Country $country, array $texts, ?int $topK = null): ?array;

    /**
     * @param  list<float>  $scores
     * @param  list<bool>  $correct
     * @return array{fitted: bool, n_samples: int, reason: string}|null
     */
    public function calibrate(array $scores, array $correct): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Country $country): array;
}
