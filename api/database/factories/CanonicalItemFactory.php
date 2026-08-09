<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CanonicalItem;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;
use Pgvector\Laravel\Vector;

/**
 * @extends Factory<CanonicalItem>
 */
final class CanonicalItemFactory extends Factory
{
    protected $model = CanonicalItem::class;

    /** Must match the vector column width in the migration. */
    private const DIMENSIONS = 768;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'country_id' => Country::factory(),
            'code' => str_replace(' ', '_', (string) $name).'_'.$this->faker->unique()->numberBetween(1, 99999),
            'name_en' => ucfirst((string) $name),
            'name_local' => null,
            'category' => $this->faker->randomElement([
                'infant_nutrition', 'staples', 'protein', 'produce',
                'medicine', 'school', 'hygiene', 'fuel', 'water',
            ]),
            'default_unit_code' => 'kg',
            'default_quantity' => 1,
            'embedding' => null,
            'embedding_model' => null,
            'embedding_updated_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * Attach a deterministic unit-norm embedding.
     *
     * Deterministic because retrieval tests must be reproducible, and unit-norm
     * because cosine distance on unnormalised vectors would make the similarity
     * scores in assertions meaningless.
     */
    public function embedded(?int $seed = null, string $model = 'intfloat/multilingual-e5-base'): self
    {
        return $this->state(function () use ($seed, $model): array {
            mt_srand($seed ?? 42);

            $values = [];
            for ($i = 0; $i < self::DIMENSIONS; $i++) {
                $values[] = mt_rand(-1000, 1000) / 1000;
            }

            $norm = sqrt(array_sum(array_map(fn (float $v): float => $v ** 2, $values)));
            $unit = array_map(fn (float $v): float => $norm > 0 ? $v / $norm : 0.0, $values);

            mt_srand();

            return [
                'embedding' => new Vector($unit),
                'embedding_model' => $model,
                'embedding_updated_at' => now(),
            ];
        });
    }

    /** An item whose embedding predates the current model, so needs a refresh. */
    public function staleEmbedding(): self
    {
        return $this->embedded(model: 'some-older-model');
    }
}
