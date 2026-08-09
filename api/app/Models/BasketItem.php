<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BasketItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BasketItem extends Model
{
    /** @use HasFactory<BasketItemFactory> */
    use HasFactory;

    protected $fillable = [
        'basket_id', 'canonical_item_id', 'weight', 'quantity',
        'unit_code', 'category', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'quantity' => 'float',
        ];
    }

    /** @return BelongsTo<Basket, $this> */
    public function basket(): BelongsTo
    {
        return $this->belongsTo(Basket::class);
    }

    /** @return BelongsTo<CanonicalItem, $this> */
    public function canonicalItem(): BelongsTo
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    /**
     * This item's contribution to the basket cost at a given unit price.
     *
     * Quantity, not weight: weight governs coverage and the normalised index,
     * quantity governs what the basket physically costs.
     */
    public function contributionAt(float $pricePerBaseUnit): float
    {
        return $this->quantity * $pricePerBaseUnit;
    }
}
