<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Unit;
use App\Services\Index\IndexCalculator;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Carbon\CarbonImmutable;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| The number the world sees, against a number from the world
|--------------------------------------------------------------------------
|
| tests/Feature/Country/BasketPlausibilityTest.php checks the *configuration*:
| given these quantities and these reference prices, does the basket come to a
| sensible figure? It does, and it always did — which is exactly why it would
| not have caught the bug that prompted all of this.
|
| That bug was in the code. `IndexCalculator` multiplied a basket quantity by a
| price per base unit without converting the quantity into base units, so sixty
| millilitres of paracetamol were costed as sixty litres and Tripoli published
| 13,250 LYD. The configuration was innocent throughout.
|
| So this test runs the real pipeline — the real country file, the real importer,
| the real calculator — feeds it observations at exactly the reference prices,
| and asserts the *published* figure lands in the band the country declares. It
| is the only test in the suite where a wrong answer of the wrong size fails,
| regardless of which layer produced it.
|
| Deliberately no synthetic generator: prices are pinned to the reference values
| so the expected total is arithmetic rather than a distribution, and a failure
| means the pipeline, never the seed.
|
*/

it('publishes a basket cost inside the band the country declares', function (): void {
    $path = base_path('../countries/ly.yaml');

    (new CountryConfigImporter)->import((new CountryConfigLoader)->load($path));

    /** @var array<string, mixed> $config */
    $config = Yaml::parseFile($path);
    $band = $config['basket']['plausible_monthly_cost_local'];

    $country = Country::query()->where('code', 'LY')->firstOrFail();
    $location = Location::query()->where('country_id', $country->id)->firstOrFail();
    $basket = Basket::query()->where('country_id', $country->id)->orderByDesc('version')->firstOrFail();

    $factors = Unit::query()
        ->where('country_id', $country->id)
        ->pluck('factor_to_base', 'code')
        ->map(fn ($f): float => (float) $f)
        ->all();

    $date = CarbonImmutable::parse('2026-03-15');

    // One observation per basket item, priced at the country file's own
    // reference price for that item — the price of one pack, converted into the
    // per-base-unit figure an observation actually stores.
    foreach ($config['demo']['reference_prices'] as $code => $price) {
        $item = CanonicalItem::query()
            ->where('country_id', $country->id)
            ->where('code', $code)
            ->first();

        if ($item === null) {
            continue;
        }

        $packInBaseUnits = (float) $item->default_quantity * $factors[$item->default_unit_code];

        PriceObservation::factory()->create([
            'country_id' => $country->id,
            'location_id' => $location->id,
            'canonical_item_id' => $item->id,
            'normalized_price_per_base_unit' => (float) $price / $packInBaseUnits,
            'observed_on' => $date->toDateString(),
            'observed_at' => $date->setTime(9, 0),
            'is_valid' => true,
        ]);
    }

    $cost = app(IndexCalculator::class)->cost($country, $location, $basket, $date);

    expect($cost->costLocal)
        ->toBeGreaterThanOrEqual(
            (float) $band['min'],
            "The published basket cost {$cost->costLocal}, below the floor of {$band['min']} that "
            .'countries/ly.yaml declares. A figure this size reaches the public API and the '
            .'dashboard.',
        )
        ->toBeLessThanOrEqual(
            (float) $band['max'],
            "The published basket cost {$cost->costLocal}, above the ceiling of {$band['max']} that "
            .'countries/ly.yaml declares. This is the assertion that a unit-conversion error in '
            .'the calculator fails — it published 13,250 once.',
        );

    // Fully priced, so the figure above is the whole basket and not a fraction
    // of one that happens to land in range.
    expect($cost->coveragePct)->toBe(1.0);
});

it('would fail loudly if a basket line were costed in the wrong unit', function (): void {
    // The regression this whole file exists for, stated as an experiment: take
    // the millilitre line and cost it as though the conversion were skipped.
    // If the guard above can survive a thousandfold error on one line, it is
    // not a guard.
    $path = base_path('../countries/ly.yaml');
    /** @var array<string, mixed> $config */
    $config = Yaml::parseFile($path);

    $items = [];
    foreach ($config['canonical_items'] as $item) {
        $items[(string) $item['code']] = $item;
    }

    $correct = 0.0;
    $unconverted = 0.0;

    foreach ($config['basket']['items'] as $line) {
        $code = (string) $line['item'];
        $price = (float) $config['demo']['reference_prices'][$code];
        $packSize = (float) $items[$code]['default_quantity'];
        $factor = match ((string) $items[$code]['default_unit_code']) {
            'g', 'ml' => 0.001,
            'dozen' => 12.0,
            default => 1.0,
        };

        $perBaseUnit = $price / ($packSize * $factor);

        $correct += (float) $line['quantity'] * $factor * $perBaseUnit;
        $unconverted += (float) $line['quantity'] * $perBaseUnit;
    }

    $band = $config['basket']['plausible_monthly_cost_local'];

    expect(round($correct, 2))->toBeLessThanOrEqual((float) $band['max'])
        ->and(round($unconverted, 2))->toBeGreaterThan(
            (float) $band['max'],
            'Skipping the unit conversion produced '.round($unconverted, 2).', which the declared '
            .'ceiling of '.$band['max'].' does not reject. Widen nothing — narrow the band.',
        );
});
