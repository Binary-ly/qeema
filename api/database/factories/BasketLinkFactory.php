<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Basket;
use App\Models\BasketLink;
use App\Models\Country;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BasketLink>
 */
final class BasketLinkFactory extends Factory
{
    protected $model = BasketLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'basket_id' => Basket::factory(),
            'location_id' => Location::factory(),
            'previous_basket_id' => null,
            'link_date' => CarbonImmutable::parse('2026-01-01')->toDateString(),
            'reference_cost' => 100.0,
            'link_factor' => null,
            'method' => BasketLink::METHOD_BASE_PERIOD,
            'computed_at' => CarbonImmutable::now(),
        ];
    }

    public function chained(Basket $previous, float $factor): self
    {
        return $this->state(fn (): array => [
            'previous_basket_id' => $previous->id,
            'link_factor' => $factor,
            'method' => BasketLink::METHOD_CHAINED,
        ]);
    }
}
