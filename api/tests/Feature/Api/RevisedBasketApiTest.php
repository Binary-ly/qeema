<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;

/*
|--------------------------------------------------------------------------
| What the API serves once a basket has been revised
|--------------------------------------------------------------------------
|
| Found on a live deployment rather than in a test: after revising the shipped
| basket, `/locations/{slug}/index/{date}` returned the *superseded* version for
| a date the new one governed. Every index endpoint had been written assuming one
| snapshot per location per date, which a revision makes false — snapshots under
| the old version remain for the dates around the changeover, and the older row
| won on insertion order alone.
|
*/

beforeEach(function (): void {
    $this->country = Country::factory()->create(['code' => 'XR', 'currency_code' => 'XTS', 'is_active' => true]);
    $this->location = Location::factory()->create([
        'country_id' => $this->country->id,
        'slug' => 'northtown',
        'is_active' => true,
    ]);
    $this->item = CanonicalItem::factory()->create(['country_id' => $this->country->id]);

    $this->v1 = Basket::factory()->create([
        'country_id' => $this->country->id,
        'version' => 1,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-03-31',
    ]);

    $this->v2 = Basket::factory()->create([
        'country_id' => $this->country->id,
        'version' => 2,
        'effective_from' => '2026-04-01',
    ]);

    foreach ([$this->v1, $this->v2] as $basket) {
        BasketItem::factory()->create([
            'basket_id' => $basket->id,
            'canonical_item_id' => $this->item->id,
            'weight' => 1.0,
            'quantity' => 1,
            'unit_code' => 'kg',
        ]);
    }
});

function snapshotUnder(Basket $basket, string $date, float $cost, ?float $level): IndexSnapshot
{
    return IndexSnapshot::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'basket_id' => $basket->id,
        'snapshot_date' => $date,
        'cost_local' => $cost,
        'index_level' => $level,
        'coverage_pct' => 1.0,
        'imputed_share' => 0.0,
    ]);
}

it('serves the basket in force on the date, not the one written first', function (): void {
    // The stale v1 row is created first, so it wins on insertion order unless
    // the query says otherwise. This is the exact shape of the live bug.
    snapshotUnder($this->v1, '2026-04-02', 100.0, 110.0);
    snapshotUnder($this->v2, '2026-04-02', 130.0, 111.0);

    $this->getJson('/api/v1/locations/northtown/index/2026-04-02')
        ->assertOk()
        ->assertJsonPath('data.index.basket_version', 2)
        ->assertJsonPath('data.cost.local', 130);
});

it('does not repeat a date in a history series', function (): void {
    snapshotUnder($this->v1, '2026-04-02', 100.0, 110.0);
    snapshotUnder($this->v2, '2026-04-02', 130.0, 111.0);
    snapshotUnder($this->v2, '2026-04-03', 131.0, 112.0);

    $response = $this->getJson('/api/v1/locations/northtown/index?from=2026-04-01&to=2026-04-05')
        ->assertOk();

    $dates = array_column($response->json('data'), 'date');

    // A repeated date would plot as a vertical step that is not in the data.
    expect($dates)->toBe(['2026-04-02', '2026-04-03'])
        ->and($response->json('data.0.index.basket_version'))->toBe(2);
});

it('reports the current figure from the current basket', function (): void {
    snapshotUnder($this->v1, '2026-04-02', 100.0, 110.0);
    snapshotUnder($this->v2, '2026-04-02', 130.0, 111.0);

    $this->getJson('/api/v1/countries/XR/index/current')
        ->assertOk()
        ->assertJsonPath('data.0.index.basket_version', 2);
});

it('exports one row per location per date, carrying the level', function (): void {
    snapshotUnder($this->v1, '2026-04-02', 100.0, 110.0);
    snapshotUnder($this->v2, '2026-04-02', 130.0, 111.0);

    $csv = $this->get('/api/v1/countries/XR/export.csv?from=2026-04-01&to=2026-04-05')->streamedContent();

    $rows = array_values(array_filter(explode("\n", trim($csv))));

    expect($rows)->toHaveCount(2)            // header plus one data row
        ->and($rows[0])->toContain('index_level')
        ->and($rows[0])->toContain('basket_version')
        ->and($rows[1])->toContain('111')     // the level from the basket in force
        ->and($rows[1])->toContain('130');    // and its cost
});

it('still labels an unanchored snapshot as having no level', function (): void {
    snapshotUnder($this->v2, '2026-04-02', 130.0, null);

    $this->getJson('/api/v1/locations/northtown/index/2026-04-02')
        ->assertOk()
        ->assertJsonPath('data.index.level', null);
});
