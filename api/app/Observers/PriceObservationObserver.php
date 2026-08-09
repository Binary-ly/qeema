<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Observers;

use App\Models\PriceObservation;
use App\Services\Index\IndexStaleness;

/**
 * Keeps published snapshots honest when the data beneath them changes.
 *
 * An observer rather than explicit calls at each write site, because the ways a
 * price observation can change are many — resolution, human review, anomaly
 * rejection, a partner re-upload — and a single missed call would leave a wrong
 * figure published with nothing to indicate it.
 */
final class PriceObservationObserver
{
    public function __construct(private readonly IndexStaleness $staleness) {}

    public function created(PriceObservation $observation): void
    {
        $this->staleness->markAffectedBy($observation);
    }

    /**
     * Any change to validity or price invalidates dependent snapshots.
     *
     * Checked against the dirty attributes rather than marking on every save:
     * touching an unrelated column should not trigger a week of recomputation.
     */
    public function updated(PriceObservation $observation): void
    {
        $material = ['is_valid', 'normalized_price_per_base_unit', 'observed_on', 'superseded_by_id'];

        if ($observation->wasChanged($material)) {
            $this->staleness->markAffectedBy($observation);
        }
    }

    public function deleted(PriceObservation $observation): void
    {
        $this->staleness->markAffectedBy($observation);
    }
}
