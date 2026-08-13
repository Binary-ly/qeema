<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\Country;
use App\Support\CountryConfig\CountryConfigImporter;

/*
|--------------------------------------------------------------------------
| Revising a basket through the country file
|--------------------------------------------------------------------------
|
| A country file describes one basket — the current one — so a revision is
| expressed by bumping `version` and `effective_from`. Nothing in that file can
| say when the previous version ended, which left both in force at once: the
| publisher picks a basket by date, and two open-ended versions meant the older
| one never stopped being selectable.
|
| The importer closes the earlier version on the day before the new one starts.
|
*/

function importBasketVersion(string $code, int $version, string $effectiveFrom): void
{
    (new CountryConfigImporter)->import([
        'country' => [
            'code' => $code,
            'name' => 'Testland',
            'currency' => ['code' => 'XTS', 'minor_units' => 2],
            'timezone' => 'UTC',
        ],
        'units' => [
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'mass', 'base_unit_code' => 'kg', 'factor_to_base' => 1],
        ],
        'locations' => [
            ['slug' => 'northtown', 'name' => 'Northtown'],
        ],
        'canonical_items' => [
            ['code' => 'rice', 'name_en' => 'Rice', 'category' => 'staples', 'default_unit_code' => 'kg'],
        ],
        'basket' => [
            'name' => 'Child Affordability Basket',
            'version' => $version,
            'effective_from' => $effectiveFrom,
            'items' => [
                ['item' => 'rice', 'weight' => 1.0, 'quantity' => 2, 'unit' => 'kg', 'category' => 'staples'],
            ],
        ],
    ]);
}

it('closes the previous version the day before the new one takes effect', function (): void {
    importBasketVersion('XB', 1, '2026-01-01');
    importBasketVersion('XB', 2, '2026-04-01');

    $country = Country::query()->where('code', 'XB')->firstOrFail();

    $v1 = $country->baskets()->where('version', 1)->firstOrFail();
    $v2 = $country->baskets()->where('version', 2)->firstOrFail();

    expect($v1->effective_to?->toDateString())->toBe('2026-03-31')
        ->and($v2->effective_to)->toBeNull();
});

it('leaves exactly one basket in force on any given day', function (): void {
    importBasketVersion('XC', 1, '2026-01-01');
    importBasketVersion('XC', 2, '2026-04-01');

    $country = Country::query()->where('code', 'XC')->firstOrFail();

    // The day before the changeover, the day of, and a day well after.
    expect($country->basketOn(new DateTimeImmutable('2026-03-31'))->version)->toBe(1)
        ->and($country->basketOn(new DateTimeImmutable('2026-04-01'))->version)->toBe(2)
        ->and($country->basketOn(new DateTimeImmutable('2026-06-01'))->version)->toBe(2);
});

it('does not disturb a version that was closed deliberately', function (): void {
    importBasketVersion('XD', 1, '2026-01-01');

    $country = Country::query()->where('code', 'XD')->firstOrFail();
    $country->baskets()->where('version', 1)->update(['effective_to' => '2026-02-15']);

    importBasketVersion('XD', 2, '2026-04-01');

    // An operator who set an end date meant it; the importer does not overrule
    // them to make the series tidy.
    expect($country->baskets()->where('version', 1)->firstOrFail()->effective_to->toDateString())
        ->toBe('2026-02-15');
});

it('re-importing an unchanged file changes nothing', function (): void {
    importBasketVersion('XE', 1, '2026-01-01');
    importBasketVersion('XE', 1, '2026-01-01');

    $country = Country::query()->where('code', 'XE')->firstOrFail();

    expect($country->baskets()->count())->toBe(1)
        ->and($country->baskets()->firstOrFail()->effective_to)->toBeNull();
});
