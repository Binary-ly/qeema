<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\BasketItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;

/**
 * Fills basket items that were not observed.
 *
 * Assembles the spatial and temporal context the model needs and calls the ML
 * service. Returns an empty result rather than a guess when the service is
 * unavailable — a partial basket that says it is partial is honest; a basket
 * silently completed with invented numbers is not.
 */
final class ItemImputer
{
    private const CONTEXT_DAYS = 7;

    public function __construct(private readonly MlClientInterface $ml) {}

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
     * @return array<string, mixed>
     */
    private function contextFor(
        Country $country,
        Location $location,
        BasketItem $entry,
        CarbonImmutable $date,
    ): array {
        $from = $date->subDays(self::CONTEXT_DAYS)->toDateString();
        $to = $date->toDateString();

        $national = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('canonical_item_id', $entry->canonical_item_id)
            ->whereBetween('observed_on', [$from, $to])
            ->valid()
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median')
            ->value('median');

        // Neighbours are every *other* location reporting this item — the
        // location being imputed contributes nothing by definition.
        $neighbour = PriceObservation::query()
            ->where('country_id', $country->id)
            ->where('canonical_item_id', $entry->canonical_item_id)
            ->where('location_id', '!=', $location->id)
            ->whereBetween('observed_on', [$from, $to])
            ->valid()
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY normalized_price_per_base_unit) AS median, COUNT(DISTINCT location_id) AS n, AVG(normalized_price_per_base_unit) AS mean')
            ->first();

        $last = PriceObservation::query()
            ->where('location_id', $location->id)
            ->where('canonical_item_id', $entry->canonical_item_id)
            ->where('observed_on', '<', $to)
            ->valid()
            ->orderByDesc('observed_on')
            ->first();

        return [
            'national_median' => (float) ($national ?? 0.0),
            'neighbour_median' => (float) ($neighbour->median ?? 0.0),
            'neighbour_weighted' => (float) ($neighbour->mean ?? 0.0),
            'neighbour_count' => (float) ($neighbour->n ?? 0),
            'nearest_neighbour_km' => 50.0,
            'last_local_price' => (float) ($last->normalized_price_per_base_unit ?? 0.0),
            'days_since_local' => $last === null ? 30.0 : (float) $last->observed_on->diffInDays($date),
            'national_trend' => 1.0,
            'fx_change_30d' => 1.0,
            'location_price_level' => 1.0,
            'day_of_week' => (float) $date->dayOfWeek,
        ];
    }
}
