<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Source;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceObservation>
 */
final class PriceObservationFactory extends Factory
{
    protected $model = PriceObservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = $this->faker->randomFloat(2, 1, 500);
        $observedOn = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'submission_id' => Submission::factory(),
            'country_id' => Country::factory(),
            'location_id' => Location::factory(),
            'canonical_item_id' => CanonicalItem::factory(),
            'price' => $price,
            'currency_code' => 'XTS',
            'unit_code' => 'kg',
            'quantity' => 1,
            'normalized_price_per_base_unit' => $price,
            'observed_on' => $observedOn,
            'observed_at' => $observedOn,
            'reporter_id' => Reporter::factory(),
            'source_id' => Source::factory(),
            'reputation_at_time' => 0.5,
            'is_valid' => true,
            'superseded_by_id' => null,
        ];
    }

    /**
     * A precise price on a precise date.
     *
     * Estimator tests need exact inputs — a weighted median over random values
     * cannot be asserted against a hand-computed expected result.
     */
    public function pricedAt(float $pricePerBaseUnit, string $observedOn): self
    {
        return $this->state(fn (): array => [
            'price' => $pricePerBaseUnit,
            'normalized_price_per_base_unit' => $pricePerBaseUnit,
            'observed_on' => $observedOn,
            'observed_at' => $observedOn.' 12:00:00',
        ]);
    }

    public function fromReporterWithReputation(float $reputation): self
    {
        return $this->state(fn (): array => ['reputation_at_time' => $reputation]);
    }

    /** Superseded by a correction; must be excluded from the index. */
    public function invalidated(): self
    {
        return $this->state(fn (): array => ['is_valid' => false]);
    }
}
