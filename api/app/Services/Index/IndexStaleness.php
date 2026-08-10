<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Index;

use App\Models\IndexSnapshot;
use App\Models\PriceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marks published snapshots as needing recomputation.
 *
 * The subtlety here is the **window**. An observation does not only affect the
 * snapshot for the day it was observed: the estimator looks back over a
 * configurable window, so a price observed on the 3rd is evidence for the
 * snapshots of the 3rd through the 10th. Marking only the observation's own day
 * would leave a week of published figures silently wrong after a correction —
 * which is worse than never correcting at all, because the error is now
 * invisible and dated.
 *
 * Marking is deliberately generous. Recomputing a snapshot that did not need it
 * costs a little work; failing to recompute one that did leaves a wrong number
 * published indefinitely.
 */
final class IndexStaleness
{
    /**
     * Mark every snapshot whose value could depend on this observation.
     *
     * @return int number of snapshots marked
     */
    public function markAffectedBy(PriceObservation $observation): int
    {
        $country = $observation->country;
        $windowDays = (int) ($country?->indexSettings()['observation_window_days'] ?? 7);

        $from = CarbonImmutable::instance($observation->observed_on->toDateTime())->startOfDay();
        $to = $from->addDays($windowDays);

        return $this->markRange($observation->location_id, $from, $to);
    }

    /**
     * Mark a date range for one location.
     */
    public function markRange(int $locationId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return IndexSnapshot::query()
            ->where('location_id', $locationId)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            // Only rows that are not already stale, so `stale_marked_at` keeps
            // the *first* reason a snapshot needed recomputing. Refreshing the
            // stamp on every subsequent observation would let a busy location
            // sit permanently inside the grace window and never republish.
            ->where('is_stale', false)
            ->update(['is_stale' => true, 'stale_marked_at' => CarbonImmutable::now()]);
    }

    /**
     * Snapshots awaiting recomputation, oldest first.
     *
     * Oldest first so a backlog is worked through in the order the data was
     * published, rather than leaving the earliest wrong figures until last.
     *
     * @return Collection<int, IndexSnapshot>
     */
    public function pending(int $limit = 500, int $graceSeconds = 0)
    {
        $cutoff = CarbonImmutable::now()->subSeconds($graceSeconds);

        return IndexSnapshot::query()
            ->stale()
            // The grace window. An observation marks its snapshots stale the
            // instant it is written, but anomaly screening happens a moment
            // later in the next job; recomputing inside that gap publishes a
            // figure containing a price nobody has screened, then corrects it
            // seconds afterwards. A null stamp predates this column and is
            // treated as old enough — refusing to recompute because nobody
            // recorded when it went stale would be the wrong way round.
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('stale_marked_at')
                    ->orWhere('stale_marked_at', '<=', $cutoff);
            })
            ->with(['location', 'basket', 'country'])
            ->orderBy('snapshot_date')
            ->limit($limit)
            ->get();
    }

    public function pendingCount(): int
    {
        return IndexSnapshot::query()->stale()->count();
    }

    /**
     * When the oldest outstanding snapshot went stale.
     *
     * Backlog *age* rather than backlog size is the signal that matters: a
     * hundred stale snapshots being worked through is healthy, and one stale
     * since this morning means recomputation has stopped.
     */
    public function oldestStaleAt(): ?CarbonImmutable
    {
        $oldest = IndexSnapshot::query()
            ->stale()
            ->whereNotNull('stale_marked_at')
            ->min('stale_marked_at');

        return $oldest === null ? null : CarbonImmutable::parse((string) $oldest);
    }

    /**
     * Mark every snapshot for a country, e.g. after a basket definition change.
     */
    public function markAllForCountry(int $countryId): int
    {
        return DB::table('index_snapshots')
            ->where('country_id', $countryId)
            ->where('is_stale', false)
            ->update(['is_stale' => true, 'stale_marked_at' => CarbonImmutable::now()]);
    }
}
