<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CanonicalItem;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexSnapshotItem>
 */
final class IndexSnapshotItemFactory extends Factory
{
    protected $model = IndexSnapshotItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->randomFloat(4, 1, 200);
        $quantity = 1.0;

        return [
            'index_snapshot_id' => IndexSnapshot::factory(),
            'canonical_item_id' => CanonicalItem::factory(),
            'unit_price_local' => $unitPrice,
            'weight' => 0.1,
            'quantity' => $quantity,
            'contribution_local' => $unitPrice * $quantity,
            'is_imputed' => false,
            'imputation_method' => null,
            'ci_low' => $unitPrice * 0.95,
            'ci_high' => $unitPrice * 1.05,
            'observation_count' => 5,
            'source_observation_ids' => [],
        ];
    }

    /**
     * An estimated price.
     *
     * Note the wider interval and zero observation count: an imputed value that
     * carried an observed value's narrow interval would misrepresent how much
     * is actually known.
     */
    public function imputed(string $method = IndexSnapshotItem::METHOD_MODEL): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_imputed' => true,
            'imputation_method' => $method,
            'observation_count' => 0,
            'source_observation_ids' => [],
            'ci_low' => $attributes['unit_price_local'] * 0.6,
            'ci_high' => $attributes['unit_price_local'] * 1.5,
        ]);
    }

    /** Imputed by the crude fallback, before the model has enough data. */
    public function imputedByFallback(): self
    {
        return $this->imputed(IndexSnapshotItem::METHOD_FALLBACK_ADMIN1);
    }

    public function pricedAt(float $unitPrice, float $quantity = 1.0, float $weight = 0.1): self
    {
        return $this->state(fn (): array => [
            'unit_price_local' => $unitPrice,
            'quantity' => $quantity,
            'weight' => $weight,
            'contribution_local' => $unitPrice * $quantity,
        ]);
    }
}
