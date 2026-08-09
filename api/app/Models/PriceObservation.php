<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PriceObservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A validated, unit-normalised price point — the only input to the index.
 *
 * Corrections supersede rather than mutate, so history stays auditable and a
 * recomputation can always reconstruct what was known at any point in time.
 */
/**
 * @property CarbonInterface $observed_on
 * @property CarbonInterface $observed_at
 */
final class PriceObservation extends Model
{
    /** @use HasFactory<PriceObservationFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'country_id', 'location_id', 'canonical_item_id',
        'price', 'currency_code', 'unit_code', 'quantity',
        'normalized_price_per_base_unit', 'observed_on', 'observed_at',
        'reporter_id', 'source_id', 'reputation_at_time',
        'is_valid', 'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'quantity' => 'decimal:4',
            'normalized_price_per_base_unit' => 'float',
            'observed_on' => 'date',
            'observed_at' => 'datetime',
            'reputation_at_time' => 'float',
            'is_valid' => 'boolean',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<CanonicalItem, $this> */
    public function canonicalItem(): BelongsTo
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    /** @return BelongsTo<Reporter, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Reporter::class);
    }

    /** @return BelongsTo<self, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Replace this observation with a corrected one.
     *
     * The old row is retained and marked invalid so the audit trail survives and
     * so the recomputation queue can tell which snapshots are now wrong.
     */
    public function supersedeWith(self $replacement): void
    {
        $this->is_valid = false;
        $this->superseded_by_id = $replacement->id;
        $this->save();
    }

    /**
     * Recency-and-reputation weight used by the estimator.
     *
     * Exponential decay with a configurable half-life, multiplied by the
     * reporter's reputation as frozen at ingestion. Frozen, not current, so that
     * recomputing an old snapshot is deterministic.
     */
    public function estimatorWeight(\DateTimeInterface $asOf, float $halfLifeDays): float
    {
        $ageDays = max(0.0, (float) $this->observed_on->diffInDays($asOf, absolute: true));
        $recency = $halfLifeDays > 0 ? 2 ** (-$ageDays / $halfLifeDays) : 1.0;

        return $recency * max(Reporter::WEIGHT_FLOOR, $this->reputation_at_time);
    }

    /** @param Builder<self> $query */
    public function scopeValid(Builder $query): void
    {
        $query->where('is_valid', true);
    }
}
