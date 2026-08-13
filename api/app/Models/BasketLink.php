<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BasketLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The anchor that gives one basket version's level series its reference point.
 *
 * @property float $reference_cost
 * @property float|null $link_factor
 * @property CarbonInterface $link_date
 */
final class BasketLink extends Model
{
    /** @use HasFactory<BasketLinkFactory> */
    use HasFactory;

    /** Anchored at the country's base date; nothing before it to chain from. */
    public const METHOD_BASE_PERIOD = 'base_period';

    /** Carried forward by this location's own ratio of the two baskets. */
    public const METHOD_CHAINED = 'chained';

    /**
     * Carried forward by the country-wide median ratio, because this location
     * could not cost both baskets completely on the link date. Recorded
     * distinctly so a level built on a borrowed factor is never mistaken for one
     * measured here.
     */
    public const METHOD_CHAINED_COUNTRY_FALLBACK = 'chained_country_fallback';

    protected $fillable = [
        'country_id', 'basket_id', 'location_id', 'previous_basket_id',
        'link_date', 'reference_cost', 'link_factor', 'method',
        'previous_cost', 'linked_cost', 'computed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'link_date' => 'date',
            'reference_cost' => 'float',
            'link_factor' => 'float',
            'previous_cost' => 'float',
            'linked_cost' => 'float',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Basket, $this> */
    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Basket, $this> */
    public function previousBasket(): BelongsTo
    {
        return $this->belongsTo(Basket::class, 'previous_basket_id');
    }

    /**
     * A level computed from this anchor rests on a factor borrowed from other
     * locations rather than measured at this one.
     */
    public function usedCountryFallback(): bool
    {
        return $this->method === self::METHOD_CHAINED_COUNTRY_FALLBACK;
    }

    public static function anchorFor(Basket $basket, Location $location): ?self
    {
        return self::query()
            ->where('basket_id', $basket->id)
            ->where('location_id', $location->id)
            ->first();
    }
}
