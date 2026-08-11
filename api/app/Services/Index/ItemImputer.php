<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\BasketItem;
use App\Models\Country;
use App\Models\Location;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;

/**
 * Fills basket items that were not observed.
 *
 * Calls the ML service with the context assembled by NowcastFeatureBuilder —
 * the same assembly the trainer uses, so the model is served the features it
 * was fitted on. Returns an empty result rather than a guess when the service
 * is unavailable: a partial basket that says it is partial is honest; a basket
 * silently completed with invented numbers is not.
 */
final class ItemImputer
{
    public function __construct(
        private readonly MlClientInterface $ml,
        private readonly NowcastFeatureBuilder $features = new NowcastFeatureBuilder,
    ) {}

    /**
     * @param  list<BasketItem>  $missing
     * @return array<int, array{value: float, lower: float, upper: float, method: string}>
     *                                                                                     keyed by canonical item id
     */
    public function impute(
        Country $country,
        Location $location,
        array $missing,
        CarbonImmutable $date,
    ): array {
        if ($missing === []) {
            return [];
        }

        $requests = [];
        $order = [];

        foreach ($missing as $entry) {
            $requests[] = $this->contextFor($country, $location, $entry, $date);
            $order[] = $entry->canonical_item_id;
        }

        $results = $this->ml->nowcast($requests);

        if ($results === null) {
            return [];
        }

        $imputations = [];

        foreach ($results as $i => $result) {
            $value = $result['value'] ?? null;

            if ($value === null || (float) $value <= 0.0) {
                continue;
            }

            $imputations[$order[$i]] = [
                'value' => (float) $value,
                'lower' => (float) ($result['lower'] ?? $value),
                'upper' => (float) ($result['upper'] ?? $value),
                'method' => (string) ($result['method'] ?? 'unknown'),
            ];
        }

        return $imputations;
    }

    /**
     * @return array<string, float>
     */
    private function contextFor(
        Country $country,
        Location $location,
        BasketItem $entry,
        CarbonImmutable $date,
    ): array {
        return $this->features->build($country, $location, $entry->canonical_item_id, $date);
    }
}
