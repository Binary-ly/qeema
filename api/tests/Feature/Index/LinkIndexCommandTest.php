<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\BasketLink;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;

/*
|--------------------------------------------------------------------------
| qeema:index:link
|--------------------------------------------------------------------------
|
| Runs unattended from the scheduler every day, which sets the bar: on a day
| when nothing has changed it must do nothing, and it must never quietly move an
| anchor that published figures already rest on.
|
*/

beforeEach(function (): void {
    $this->country = Country::factory()->create([
        'code' => 'XA',
        'currency_code' => 'XTS',
        'is_active' => true,
        'index_config' => [
            'observation_window_days' => 7,
            'recency_half_life_days' => 3,
            'min_observations_for_ci' => 3,
            'bootstrap_draws' => 50,
            'base_date' => '2026-01-01',
        ],
        'fx_config' => ['provider' => 'manual', 'rate_type' => 'parallel', 'max_staleness_days' => 7],
    ]);

    $this->location = Location::factory()->create([
        'country_id' => $this->country->id,
        'is_active' => true,
    ]);

    $this->item = CanonicalItem::factory()->create([
        'country_id' => $this->country->id,
        'code' => 'rice',
    ]);
});

function commandBasket(int $version, string $from, ?string $to = null): Basket
{
    $basket = Basket::factory()->create([
        'country_id' => test()->country->id,
        'version' => $version,
        'effective_from' => $from,
        'effective_to' => $to,
    ]);

    BasketItem::factory()->create([
        'basket_id' => $basket->id,
        'canonical_item_id' => test()->item->id,
        'weight' => 1.0,
        'quantity' => 2,
        'unit_code' => 'kg',
    ]);

    return $basket;
}

function commandPrice(string $date, float $price = 5.0): void
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
    ]);

    PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'canonical_item_id' => test()->item->id,
        'normalized_price_per_base_unit' => $price,
        'observed_on' => $date,
        'observed_at' => $date.' 12:00:00',
        'reputation_at_time' => 1.0,
    ]);
}

it('anchors a first basket and reports what it did', function (): void {
    commandBasket(1, '2026-01-01');
    commandPrice('2026-01-01');

    $this->artisan('qeema:index:link')
        ->expectsOutputToContain('1 location(s) anchored')
        ->assertSuccessful();

    expect(BasketLink::query()->count())->toBe(1);
});

it('does nothing on a second run', function (): void {
    commandBasket(1, '2026-01-01');
    commandPrice('2026-01-01');

    $this->artisan('qeema:index:link')->assertSuccessful();
    $before = BasketLink::query()->first()->updated_at;

    $this->artisan('qeema:index:link')
        ->expectsOutputToContain('already anchored')
        ->assertSuccessful();

    expect(BasketLink::query()->count())->toBe(1)
        ->and(BasketLink::query()->first()->updated_at->eq($before))->toBeTrue();
});

it('anchors versions in order so a later one has something to chain from', function (): void {
    $v1 = commandBasket(1, '2026-01-01', '2026-03-31');
    $v2 = commandBasket(2, '2026-04-01');

    commandPrice('2026-01-01');
    commandPrice('2026-03-31');

    // One invocation, both versions. v2 chains from v1's anchor, which only
    // exists because v1 was processed first in the same run.
    $this->artisan('qeema:index:link')->assertSuccessful();

    expect(BasketLink::anchorFor($v1, $this->location))->not->toBeNull()
        ->and(BasketLink::anchorFor($v2, $this->location)?->method)
        ->toBe(BasketLink::METHOD_CHAINED);
});

it('fails when a named country does not exist', function (): void {
    // A typo in an operator's command should say so rather than succeed having
    // silently anchored nothing.
    $this->artisan('qeema:index:link', ['--country' => 'ZZ'])
        ->expectsOutputToContain('No active country')
        ->assertFailed();
});

it('succeeds on an install with no countries yet', function (): void {
    Country::query()->update(['is_active' => false]);

    // Bootstrap calls this before any country exists; failing would break a
    // first install.
    $this->artisan('qeema:index:link')->assertSuccessful();
});

it('anchors a country that configures no base period', function (): void {
    $this->country->forceFill([
        'index_config' => array_merge($this->country->index_config, ['base_date' => null]),
    ])->save();

    commandBasket(1, '2026-01-01');
    commandPrice('2026-02-10');

    $this->artisan('qeema:index:link')
        ->expectsOutputToContain('1 location(s) anchored')
        ->assertSuccessful();

    expect(BasketLink::query()->first()->link_date->toDateString())->toBe('2026-02-10');
});

it('replaces an anchor only when forced', function (): void {
    commandBasket(1, '2026-01-01');
    commandPrice('2026-01-01', price: 5.0);

    $this->artisan('qeema:index:link')->assertSuccessful();
    $original = BasketLink::query()->first()->reference_cost;

    commandPrice('2026-01-01', price: 50.0);
    $this->artisan('qeema:index:link', ['--force' => true])->assertSuccessful();

    expect(BasketLink::query()->first()->reference_cost)->toBeGreaterThan($original);
});

it('can be pointed at a single basket version', function (): void {
    $v1 = commandBasket(1, '2026-01-01', '2026-03-31');
    $v2 = commandBasket(2, '2026-04-01');

    commandPrice('2026-01-01');
    commandPrice('2026-03-31');

    $this->artisan('qeema:index:link', ['--basket' => 1])->assertSuccessful();

    expect(BasketLink::anchorFor($v1, $this->location))->not->toBeNull()
        ->and(BasketLink::anchorFor($v2, $this->location))->toBeNull();
});
