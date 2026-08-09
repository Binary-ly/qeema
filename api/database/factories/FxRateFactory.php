<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\FxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FxRate>
 */
final class FxRateFactory extends Factory
{
    protected $model = FxRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $official = $this->faker->randomFloat(4, 1, 10);

        return [
            'country_id' => Country::factory(),
            'rate_date' => now()->toDateString(),
            'official_rate' => $official,
            // A parallel rate above the official one is the normal condition in
            // the economies this platform targets.
            'parallel_rate' => $official * $this->faker->randomFloat(2, 1.1, 2.5),
            'base_currency' => 'USD',
            'source' => 'manual',
            'is_manual' => true,
            'raw' => null,
            'fetched_at' => now(),
        ];
    }

    public function on(string $date): self
    {
        return $this->state(fn (): array => ['rate_date' => $date]);
    }

    public function withRates(?float $official, ?float $parallel): self
    {
        return $this->state(fn (): array => [
            'official_rate' => $official,
            'parallel_rate' => $parallel,
        ]);
    }

    /** Only an official rate exists, so parallel conversion must fall back. */
    public function officialOnly(): self
    {
        return $this->state(fn (array $attributes): array => [
            'parallel_rate' => null,
            'official_rate' => $attributes['official_rate'],
        ]);
    }
}
