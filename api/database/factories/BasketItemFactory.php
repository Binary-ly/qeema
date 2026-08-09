<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasketItem>
 */
final class BasketItemFactory extends Factory
{
    protected $model = BasketItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'basket_id' => Basket::factory(),
            'canonical_item_id' => CanonicalItem::factory(),
            'weight' => 0.1,
            'quantity' => 1.0,
            'unit_code' => 'kg',
            'category' => 'staples',
            'notes' => null,
        ];
    }

    public function weighing(float $weight): self
    {
        return $this->state(fn (): array => ['weight' => $weight]);
    }

    public function ofQuantity(float $quantity, string $unitCode = 'kg'): self
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
            'unit_code' => $unitCode,
        ]);
    }
}
