<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IndexSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One costed basket, for one location, on one date. The published output.
 *
 * Every figure here is paired with something that qualifies it — coverage,
 * imputed share, a confidence interval, and whether the exchange rate was stale.
 * A cost with 40% of its weight imputed against a nine-day-old rate is a
 * legitimate estimate; publishing it as though it were measured would not be.
 */
final class IndexSnapshot extends Model
{
    /** @use HasFactory<IndexSnapshotFactory> */
    use HasFactory;

    /**
     * Below this share of observed weight the snapshot is published but marked
     * low-confidence in the API and visually de-emphasised in the dashboard.
     */
    public const LOW_COVERAGE_THRESHOLD = 0.5;

    protected $fillable = [
        'country_id', 'location_id', 'basket_id', 'snapshot_date',
        'cost_local', 'cost_usd', 'normalized_index',
        'coverage_pct', 'imputed_share', 'ci_low_local', 'ci_high_local',
        'fx_rate_used', 'fx_rate_type', 'fx_rate_date', 'fx_is_stale',
        'observed_item_count', 'total_item_count',
        'is_stale', 'computed_at', 'model_version',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'cost_local' => 'float',
            'cost_usd' => 'float',
            'normalized_index' => 'float',
            'coverage_pct' => 'float',
            'imputed_share' => 'float',
            'ci_low_local' => 'float',
            'ci_high_local' => 'float',
            'fx_rate_used' => 'float',
            'fx_rate_date' => 'date',
            'fx_is_stale' => 'boolean',
            'observed_item_count' => 'integer',
            'total_item_count' => 'integer',
            'is_stale' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Basket, $this> */
    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<IndexSnapshotItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(IndexSnapshotItem::class);
    }

    public function hasLowCoverage(): bool
    {
        return $this->coverage_pct < self::LOW_COVERAGE_THRESHOLD;
    }

    /**
     * A single honest summary of how much this figure should be trusted.
     *
     * Exposed on the public API so a consumer does not have to reimplement the
     * judgement from the constituent fields — and so they cannot quietly skip it.
     */
    public function qualityLabel(): string
    {
        return match (true) {
            $this->hasLowCoverage() => 'low',
            $this->imputed_share > 0.3 || $this->fx_is_stale => 'moderate',
            default => 'good',
        };
    }

    /** Mark for recomputation; a queued job picks it up. Idempotent. */
    public function markStale(): void
    {
        if (! $this->is_stale) {
            $this->forceFill(['is_stale' => true])->save();
        }
    }

    /** @param Builder<self> $query */
    public function scopeStale(Builder $query): void
    {
        $query->where('is_stale', true);
    }
}
