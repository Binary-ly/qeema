<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BasketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $effective_to
 */
final class Basket extends Model
{
    /** @use HasFactory<BasketFactory> */
    use HasFactory;

    /** Weights are floating point, so exact equality to 1.0 is not testable. */
    public const WEIGHT_SUM_TOLERANCE = 1e-6;

    protected $fillable = [
        'country_id', 'name', 'version',
        'effective_from', 'effective_to', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<BasketItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BasketItem::class);
    }

    public function weightSum(): float
    {
        return (float) $this->items()->sum('weight');
    }

    /**
     * Weights must sum to 1. A basket that does not satisfy this silently
     * distorts both coverage and the normalised index, so it is checked rather
     * than assumed.
     */
    public function hasValidWeights(): bool
    {
        return abs($this->weightSum() - 1.0) <= self::WEIGHT_SUM_TOLERANCE;
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        if ($this->effective_from->greaterThan($date)) {
            return false;
        }

        return $this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($date);
    }
}
