<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IndexSnapshotItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's contribution to a snapshot.
 *
 * `is_imputed` originates here and must survive every transformation between
 * this row and a pixel on a chart. If you are writing a serialiser, a CSV
 * exporter or a chart series and you find yourself dropping this field for
 * convenience: don't.
 */
final class IndexSnapshotItem extends Model
{
    /** @use HasFactory<IndexSnapshotItemFactory> */
    use HasFactory;

    public const METHOD_OBSERVED = null;

    public const METHOD_MODEL = 'lightgbm_quantile';

    public const METHOD_FALLBACK_ADMIN1 = 'fallback_admin1_median';

    public const METHOD_FALLBACK_NATIONAL = 'fallback_national_median';

    protected $fillable = [
        'index_snapshot_id', 'canonical_item_id',
        'unit_price_local', 'weight', 'quantity', 'contribution_local',
        'is_imputed', 'imputation_method', 'ci_low', 'ci_high',
        'observation_count', 'source_observation_ids',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_local' => 'float',
            'weight' => 'float',
            'quantity' => 'float',
            'contribution_local' => 'float',
            'is_imputed' => 'boolean',
            'ci_low' => 'float',
            'ci_high' => 'float',
            'observation_count' => 'integer',
            'source_observation_ids' => 'array',
        ];
    }

    /** @return BelongsTo<IndexSnapshot, $this> */
    public function indexSnapshot(): BelongsTo
    {
        return $this->belongsTo(IndexSnapshot::class);
    }

    /** @return BelongsTo<CanonicalItem, $this> */
    public function canonicalItem(): BelongsTo
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    /**
     * Width of the interval relative to the estimate.
     *
     * A useful single number for deciding whether an estimate is precise enough
     * to act on: 0.05 is a tight estimate, 0.8 is barely informative.
     */
    public function relativeIntervalWidth(): ?float
    {
        if ($this->ci_low === null || $this->ci_high === null || $this->unit_price_local <= 0.0) {
            return null;
        }

        return ($this->ci_high - $this->ci_low) / $this->unit_price_local;
    }

    /** @param Builder<self> $query */
    public function scopeObserved(Builder $query): void
    {
        $query->where('is_imputed', false);
    }

    /** @param Builder<self> $query */
    public function scopeImputed(Builder $query): void
    {
        $query->where('is_imputed', true);
    }
}
