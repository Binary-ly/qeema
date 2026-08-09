<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A unit of measure and its factor to the base unit of its dimension.
 *
 * Normalisation happens here rather than in the estimator so that there is
 * exactly one place in the codebase that knows a kilo is a thousand grams.
 */
final class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $fillable = [
        'country_id', 'code', 'name', 'name_local',
        'dimension', 'base_unit_code', 'factor_to_base',
    ];

    protected function casts(): array
    {
        return ['factor_to_base' => 'float'];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Convert a submitted price into a price per base unit.
     *
     * Example: 12.50 for a 500 g pack, where g has factor_to_base 0.001 against
     * kg, yields 25.00 per kg.
     *
     * @param  float  $price  price paid for `$quantity` of this unit
     * @param  float  $quantity  how many of this unit were bought
     */
    public function pricePerBaseUnit(float $price, float $quantity): float
    {
        if ($quantity <= 0.0) {
            throw new InvalidArgumentException('Quantity must be positive to normalise a price.');
        }

        $baseQuantity = $quantity * $this->factor_to_base;

        if ($baseQuantity <= 0.0) {
            throw new InvalidArgumentException(
                "Unit {$this->code} has a non-positive conversion factor; cannot normalise."
            );
        }

        return $price / $baseQuantity;
    }
}
