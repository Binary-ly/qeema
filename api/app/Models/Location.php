<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /** Mean Earth radius in kilometres, for haversine distance. */
    private const EARTH_RADIUS_KM = 6371.0088;

    protected $fillable = [
        'country_id', 'admin1_name', 'admin1_code', 'admin2_name', 'admin2_code',
        'name', 'name_local', 'slug', 'latitude', 'longitude',
        'population_estimate', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'population_estimate' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<PriceObservation, $this> */
    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }

    /** @return HasMany<IndexSnapshot, $this> */
    public function indexSnapshots(): HasMany
    {
        return $this->hasMany(IndexSnapshot::class);
    }

    /**
     * Great-circle distance to another location, in kilometres.
     *
     * Straight-line distance rather than road distance: constraint C1 rules out
     * a commercial routing service, and for "which nearby towns should inform an
     * estimate here" the approximation is entirely adequate.
     */
    public function distanceKmTo(self $other): ?float
    {
        if ($this->latitude === null || $this->longitude === null
            || $other->latitude === null || $other->longitude === null) {
            return null;
        }

        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($other->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Nearest sibling locations, for spatial imputation features.
     *
     * @return Collection<int, self>
     */
    public function nearestNeighbours(int $limit = 3): Collection
    {
        if ($this->latitude === null || $this->longitude === null) {
            return collect();
        }

        return self::query()
            ->where('country_id', $this->country_id)
            ->where('id', '!=', $this->id)
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn (self $l): float => $this->distanceKmTo($l) ?? INF)
            ->take($limit)
            ->values();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
