<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An exchange rate observation.
 *
 * Both the official and the parallel rate are stored. Which one converts the
 * basket cost is country configuration, defaulting to parallel — the official
 * rate in a crisis economy typically describes a transaction ordinary people
 * cannot access, so a USD figure derived from it would understate hardship.
 */
final class FxRate extends Model
{
    /** @use HasFactory<FxRateFactory> */
    use HasFactory;

    public const TYPE_PARALLEL = 'parallel';

    public const TYPE_OFFICIAL = 'official';

    public const TYPE_MANUAL = 'manual';

    protected $fillable = [
        'country_id', 'rate_date', 'official_rate', 'parallel_rate',
        'base_currency', 'source', 'is_manual', 'raw', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'official_rate' => 'float',
            'parallel_rate' => 'float',
            'is_manual' => 'boolean',
            'raw' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * The rate for a requested type, falling back to the other if absent.
     *
     * Returns null rather than guessing when neither is present. A snapshot with
     * no usable rate publishes `cost_usd = null`, which is honest; inventing a
     * rate would not be.
     */
    public function rateFor(string $type): ?float
    {
        return match ($type) {
            self::TYPE_OFFICIAL => $this->official_rate ?? $this->parallel_rate,
            default => $this->parallel_rate ?? $this->official_rate,
        };
    }

    /**
     * The gap between official and parallel rates, as a fraction.
     *
     * This spread is itself a headline indicator of economic stress, so it is
     * computed here and surfaced by the API rather than left to consumers.
     */
    public function parallelPremium(): ?float
    {
        if ($this->official_rate === null || $this->parallel_rate === null
            || $this->official_rate <= 0.0) {
            return null;
        }

        return ($this->parallel_rate - $this->official_rate) / $this->official_rate;
    }
}
