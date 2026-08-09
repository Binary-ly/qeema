<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Basket;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Basket>
 */
final class BasketFactory extends Factory
{
    protected $model = Basket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => 'Child Affordability Basket',
            'version' => 1,
            'effective_from' => now()->subMonths(6)->toDateString(),
            'effective_to' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }

    /** A superseded version, for chain-linking tests across basket changes. */
    public function superseded(string $endedOn): self
    {
        return $this->state(fn (): array => [
            'effective_to' => $endedOn,
            'is_active' => false,
        ]);
    }

    public function version(int $version): self
    {
        return $this->state(fn (): array => ['version' => $version]);
    }
}
