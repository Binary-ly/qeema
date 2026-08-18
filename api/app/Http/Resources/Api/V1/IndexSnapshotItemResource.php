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
        $disclosed = $this->disclosedObservationCount();

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
            // Zero on an imputed row, by construction. Null when a real but
            // small count was withheld — see below.
            'observation_count' => $disclosed,
            // Why the count is what it is, so that a withheld count is never
            // mistaken for missing data. 'exact' means the number above is the
            // true count; 'withheld' means the true count is between 1 and
            // `config('qeema.privacy.min_disclosed_observations') - 1`, and
            // stating it precisely would describe an identifiable person's
            // reporting rather than a market.
            'observation_count_disclosure' => $disclosed === null ? 'withheld' : 'exact',
        ];
    }

    /**
     * The observation count as the public is allowed to see it.
     *
     * Applied here, at the edge, rather than at the source. The stored count is
     * untouched: the operator's admin view, the audit trail and every internal
     * computation still see the real number. Only what leaves the building is
     * reduced, which is the difference between protecting reporters and losing
     * data.
     *
     * Zero passes through unchanged. An imputed row had no observations at all,
     * so it describes nobody, and blanking it would hide the imputation signal
     * that matters far more than the count does.
     */
    private function disclosedObservationCount(): ?int
    {
        $count = (int) $this->observation_count;
        $threshold = (int) config('qeema.privacy.min_disclosed_observations', 5);

        if ($count === 0 || $count >= $threshold) {
            return $count;
        }

        return null;
    }
}
