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
     * Score submissions for anomalies.
     *
     * @param  list<array<string, mixed>>  $observations  price plus the context needed to judge it
     * @return list<array<string, mixed>>|null one verdict per observation, in order
     */
    public function scoreAnomalies(array $observations): ?array;

    /**
     * Fit the nowcast models on observed history.
     *
     * The country is required, not inferred: the service keeps one fitted model
     * per country, and serving one country's prices from another's model is the
     * mistake this parameter exists to make impossible.
     *
     * @param  list<array<string, float>>  $features
     * @param  list<float>  $targets  each target is a ratio to the national median
     * @return array{trained: bool, n_samples: int, reason: string}|null
     */
    public function trainNowcast(Country $country, array $features, array $targets): ?array;

    /**
     * Impute prices for cells with no observation.
     *
     * Every result is labelled imputed. There is no shape this can return that
     * a caller could mistake for a measurement.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>|null one result per request, in order
     */
    public function nowcast(Country $country, array $requests): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Country $country): array;
}
