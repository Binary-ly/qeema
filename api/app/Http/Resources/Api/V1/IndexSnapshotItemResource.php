<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\IndexSnapshotItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item's contribution to a published snapshot.
 *
 * @mixin IndexSnapshotItem
 *
 * `is_imputed` is emitted unconditionally and first. It is the single most
 * important field in this payload: a consumer that treats an estimate as a
 * measurement will draw conclusions the data does not support, and the only
 * defence is that the flag is impossible to miss and never absent.
 */
final class IndexSnapshotItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_imputed' => (bool) $this->is_imputed,
            'imputation_method' => $this->imputation_method,
            'item' => [
                'code' => $this->canonicalItem->code,
                'name_en' => $this->canonicalItem->name_en,
                'name_local' => $this->canonicalItem->name_local,
                'category' => $this->canonicalItem->category,
            ],
            'unit_price' => (float) $this->unit_price_local,
            'quantity' => (float) $this->quantity,
            'weight' => (float) $this->weight,
            'contribution' => (float) $this->contribution_local,
            'confidence_low' => $this->ci_low === null ? null : (float) $this->ci_low,
            'confidence_high' => $this->ci_high === null ? null : (float) $this->ci_high,
            // Zero on an imputed row, by construction.
            'observation_count' => $this->observation_count,
        ];
    }
}
