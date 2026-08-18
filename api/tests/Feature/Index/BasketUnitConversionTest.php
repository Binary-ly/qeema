<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Index\IndexCalculator;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| A basket quantity is not a number, it is a number with a unit
|--------------------------------------------------------------------------
|
| Observations are stored as `normalized_price_per_base_unit` — per kilogram,
| per litre, per piece. A basket line says "60 ml". Costing it means converting
| the basket quantity into base units *before* multiplying, and the calculator
| did not: it multiplied 60 by a price per litre and charged for sixty litres of
| paediatric paracetamol.
|
| Nothing caught it. Every existing costing test uses kg, l or piece — units
| whose `factor_to_base` is exactly 1 — so the missing multiplication was
| invisible in all of them. The bug needed a fixture where the basket unit is
| *not* the base unit, and no such fixture existed.
|
| These tests are that fixture. The first one fails by a factor of 1000 against
| the implementation as it was.
|
*/

beforeEach(function (): void {
    $this->country = Country::factory()->create(['currency_code' => 'LYD']);
    $this->location = Location::factory()->create(['country_id' => $this->country->id]);

    // Units come from the country factory, which seeds exactly what a real
    // country file declares: kg and l at factor 1, g and ml at 0.001, piece and
    // pack at 1, dozen at 12.
});

/**
 * One basket line, one observation, costed on the day.
 *
 * @param  float  $perBaseUnit  price per kilogram / litre / piece
 */
function costOneLine(string $unitCode, float $basketQuantity, float $perBaseUnit): float
{
    $item = CanonicalItem::factory()->create([
        'country_id' => test()->country->id,
        'default_unit_code' => $unitCode,
        'default_quantity' => $basketQuantity,
    ]);

    // Versions are unique per country and one test costs several baskets.
    $version = Basket::query()->where('country_id', test()->country->id)->max('version') ?? 0;

    $basket = Basket::factory()->create([
        'country_id' => test()->country->id,
        'version' => $version + 1,
        'effective_from' => CarbonImmutable::parse('2026-01-01'),
    ]);

    BasketItem::query()->create([
        'basket_id' => $basket->id,
        'canonical_item_id' => $item->id,
        'weight' => 1.0,
        'quantity' => $basketQuantity,
        'unit_code' => $unitCode,
        'category' => 'test',
    ]);

    PriceObservation::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'canonical_item_id' => $item->id,
        'normalized_price_per_base_unit' => $perBaseUnit,
        'observed_on' => '2026-03-01',
        'observed_at' => CarbonImmutable::parse('2026-03-01 09:00'),
        'is_valid' => true,
    ]);

    return (float) app(IndexCalculator::class)->cost(
        test()->country,
        test()->location,
        $basket,
        CarbonImmutable::parse('2026-03-01'),
    )->costLocal;
}

it('costs sixty millilitres as sixty millilitres, not sixty litres', function (): void {
    // A 60 ml bottle of paediatric paracetamol. At 133.33 LYD per litre that is
    // 8 LYD — the price of one bottle. Multiplying 60 by the per-litre price
    // charges 8,000 LYD, which is what the published Tripoli basket was doing.
    expect(costOneLine('ml', 60.0, 133.3333))->toEqualWithDelta(8.0, 0.01);
});

it('costs grams as grams', function (): void {
    // 400 g of infant formula at 80 LYD per kilogram is 32 LYD.
    expect(costOneLine('g', 400.0, 80.0))->toEqualWithDelta(32.0, 0.01);
});

it('costs a dozen as twelve pieces', function (): void {
    // The opposite error: a factor above one. Two dozen eggs at 0.80 a piece is
    // 19.20, not 1.60.
    expect(costOneLine('dozen', 2.0, 0.80))->toEqualWithDelta(19.20, 0.01);
});

it('still costs base units exactly as before', function (): void {
    // The regression guard. Every pre-existing costing test uses these units,
    // and the fix must not move any of them.
    expect(costOneLine('kg', 5.0, 4.2))->toEqualWithDelta(21.0, 0.01)
        ->and(costOneLine('l', 2.0, 9.0))->toEqualWithDelta(18.0, 0.01)
        ->and(costOneLine('piece', 30.0, 0.80))->toEqualWithDelta(24.0, 0.01);
});

it('refuses to cost a basket line whose unit the country does not define', function (): void {
    // Silently treating an unknown unit as a factor of 1 is how this class of
    // error survives: it produces a number rather than a complaint.
    $item = CanonicalItem::factory()->create(['country_id' => $this->country->id]);
    $basket = Basket::factory()->create([
        'country_id' => $this->country->id,
        'effective_from' => CarbonImmutable::parse('2026-01-01'),
    ]);

    BasketItem::query()->create([
        'basket_id' => $basket->id,
        'canonical_item_id' => $item->id,
        'weight' => 1.0,
        'quantity' => 3.0,
        'unit_code' => 'furlong',
        'category' => 'test',
    ]);

    PriceObservation::factory()->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'canonical_item_id' => $item->id,
        'normalized_price_per_base_unit' => 5.0,
        'observed_on' => '2026-03-01',
        'observed_at' => CarbonImmutable::parse('2026-03-01 09:00'),
        'is_valid' => true,
    ]);

    expect(fn () => app(IndexCalculator::class)->cost(
        $this->country,
        $this->location,
        $basket,
        CarbonImmutable::parse('2026-03-01'),
    ))->toThrow(RuntimeException::class, 'furlong');
});
