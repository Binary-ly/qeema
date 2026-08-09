<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => null,
            'code' => 'kg',
            'name' => 'Kilogram',
            'name_local' => null,
            'dimension' => 'mass',
            'base_unit_code' => 'kg',
            'factor_to_base' => 1.0,
        ];
    }

    /** Gram: the sub-unit that makes unit-confusion errors realistic. */
    public function gram(): self
    {
        return $this->state(fn (): array => [
            'code' => 'g',
            'name' => 'Gram',
            'dimension' => 'mass',
            'base_unit_code' => 'kg',
            'factor_to_base' => 0.001,
        ]);
    }

    public function litre(): self
    {
        return $this->state(fn (): array => [
            'code' => 'l',
            'name' => 'Litre',
            'dimension' => 'volume',
            'base_unit_code' => 'l',
            'factor_to_base' => 1.0,
        ]);
    }

    public function millilitre(): self
    {
        return $this->state(fn (): array => [
            'code' => 'ml',
            'name' => 'Millilitre',
            'dimension' => 'volume',
            'base_unit_code' => 'l',
            'factor_to_base' => 0.001,
        ]);
    }

    /** Countable goods: notebooks, pens, sachets of oral rehydration salts. */
    public function piece(): self
    {
        return $this->state(fn (): array => [
            'code' => 'piece',
            'name' => 'Piece',
            'dimension' => 'count',
            'base_unit_code' => 'piece',
            'factor_to_base' => 1.0,
        ]);
    }
}
