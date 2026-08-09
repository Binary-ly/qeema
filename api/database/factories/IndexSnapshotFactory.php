<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Basket;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IndexSnapshot>
 */
final class IndexSnapshotFactory extends Factory
{
    protected $model = IndexSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $costLocal = $this->faker->randomFloat(2, 100, 2000);
        $fxRate = $this->faker->randomFloat(4, 1, 10);

        return [
            'country_id' => Country::factory(),
            'location_id' => Location::factory(),
            'basket_id' => Basket::factory(),
            'snapshot_date' => now()->toDateString(),
            'cost_local' => $costLocal,
            'cost_usd' => round($costLocal / $fxRate, 2),
            'normalized_index' => 100.0,
            'coverage_pct' => 1.0,
            'imputed_share' => 0.0,
            'ci_low_local' => $costLocal * 0.95,
            'ci_high_local' => $costLocal * 1.05,
            'fx_rate_used' => $fxRate,
            'fx_rate_type' => 'parallel',
            'fx_rate_date' => now()->toDateString(),
            'fx_is_stale' => false,
            'observed_item_count' => 15,
            'total_item_count' => 15,
            'is_stale' => false,
            'computed_at' => now(),
            'model_version' => 'index-0.1.0',
        ];
    }

    /**
     * A sparsely-covered snapshot leaning heavily on imputation.
     *
     * This is the normal condition in a crisis, and the case where the API must
     * be loudest about uncertainty — so it gets a first-class factory state.
     */
    public function sparse(float $coverage = 0.35, float $imputedShare = 0.65): self
    {
        return $this->state(fn (array $attributes): array => [
            'coverage_pct' => $coverage,
            'imputed_share' => $imputedShare,
            'observed_item_count' => (int) round($coverage * 15),
            'ci_low_local' => $attributes['cost_local'] * 0.7,
            'ci_high_local' => $attributes['cost_local'] * 1.4,
        ]);
    }

    /** No usable exchange rate, so cost_usd must be null rather than invented. */
    public function withoutUsableFxRate(): self
    {
        return $this->state(fn (): array => [
            'cost_usd' => null,
            'fx_rate_used' => null,
            'fx_rate_type' => null,
            'fx_rate_date' => null,
            'fx_is_stale' => true,
        ]);
    }

    public function staleFxRate(int $daysOld = 9): self
    {
        return $this->state(fn (): array => [
            'fx_rate_date' => now()->subDays($daysOld)->toDateString(),
            'fx_is_stale' => true,
        ]);
    }

    public function needingRecomputation(): self
    {
        return $this->state(fn (): array => ['is_stale' => true]);
    }

    public function on(string $date): self
    {
        return $this->state(fn (): array => ['snapshot_date' => $date]);
    }
}
