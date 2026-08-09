<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IndexSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published index snapshot.
 *
 * @mixin IndexSnapshot
 *
 * Every qualifier travels with the number. `coverage`, `imputed_share`,
 * `comparable` and the FX staleness flag are not optional extras a consumer may
 * ignore — they are the difference between a figure that can be ranked against
 * another location and one that cannot. Omitting them to make the payload
 * tidier would hand every consumer the same trap.
 */
final class IndexSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'location' => [
                'slug' => $this->location->slug,
                'name' => $this->location->name,
                'name_local' => $this->location->name_local,
                'latitude' => $this->location->latitude,
                'longitude' => $this->location->longitude,
            ],
            'date' => $this->snapshot_date->toDateString(),
            'cost' => [
                'local' => (float) $this->cost_local,
                'currency' => $this->country->currency_code,
                // Null when no usable exchange rate existed. Never an invented
                // conversion presented as dollars.
                'usd' => $this->cost_usd === null ? null : (float) $this->cost_usd,
                'confidence_low' => $this->ci_low_local === null ? null : (float) $this->ci_low_local,
                'confidence_high' => $this->ci_high_local === null ? null : (float) $this->ci_high_local,
            ],
            'quality' => [
                'coverage' => (float) $this->coverage_pct,
                'imputed_share' => (float) $this->imputed_share,
                'observed_items' => $this->observed_item_count,
                'total_items' => $this->total_item_count,
                'label' => $this->qualityLabel(),
                // False until every basket item has a price. A consumer ranking
                // locations must check this: a partially-observed basket costs
                // less simply because part of it is missing.
                'comparable' => $this->isComparable(),
            ],
            'exchange_rate' => [
                'rate' => $this->fx_rate_used === null ? null : (float) $this->fx_rate_used,
                'type' => $this->fx_rate_type,
                'date' => $this->fx_rate_date?->toDateString(),
                'is_stale' => (bool) $this->fx_is_stale,
            ],
            'items' => IndexSnapshotItemResource::collection($this->whenLoaded('items')),
            'computed_at' => $this->computed_at?->toIso8601String(),
            'model_version' => $this->model_version,
        ];
    }
}
