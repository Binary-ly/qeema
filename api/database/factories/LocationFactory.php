<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Location>
 */
final class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->city();

        return [
            'country_id' => Country::factory(),
            'admin1_name' => $this->faker->unique()->words(2, true),
            'admin1_code' => strtoupper($this->faker->lexify('??')),
            'admin2_name' => null,
            'admin2_code' => null,
            'name' => $name,
            'name_local' => null,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'population_estimate' => $this->faker->numberBetween(5_000, 2_000_000),
            'is_active' => true,
        ];
    }

    /**
     * Place the location at explicit coordinates.
     *
     * Spatial-neighbour tests need deterministic distances; random coordinates
     * would make "the three nearest locations" unpredictable.
     */
    public function at(float $latitude, float $longitude): self
    {
        return $this->state(fn (): array => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    /** A location with no coordinates, to exercise the degraded spatial path. */
    public function withoutCoordinates(): self
    {
        return $this->state(fn (): array => ['latitude' => null, 'longitude' => null]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
