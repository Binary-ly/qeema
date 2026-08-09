<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
final class SourceFactory extends Factory
{
    protected $model = Source::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'country_id' => Country::factory(),
            'type' => Source::TYPE_REPORTER,
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'url' => null,
            'license' => null,
            'contact' => null,
            'config' => null,
            'last_run_at' => null,
            'is_active' => true,
        ];
    }

    public function partnerUpload(): self
    {
        return $this->state(fn (): array => [
            'type' => Source::TYPE_PARTNER_UPLOAD,
            'license' => 'CC-BY-4.0',
            'contact' => $this->faker->safeEmail(),
        ]);
    }

    public function scraper(?string $cursor = null): self
    {
        return $this->state(fn (): array => [
            'type' => Source::TYPE_SCRAPER,
            'url' => $this->faker->url(),
            'license' => 'ODbL-1.0',
            'config' => [
                'cursor' => $cursor,
                'rate_limit_per_minute' => 20,
                'respect_robots' => true,
            ],
        ]);
    }
}
