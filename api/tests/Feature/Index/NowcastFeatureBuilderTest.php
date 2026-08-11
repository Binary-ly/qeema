<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Index\NowcastFeatureBuilder;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| What the nowcast model is allowed to know
|--------------------------------------------------------------------------
|
| Two failures are being guarded against, and both are silent.
|
| **Lookahead.** Training rows are built from prices that were observed, with
| features assembled as though they had not been. A feature that can see the
| target teaches the model to read the answer off its own input: it evaluates
| beautifully, ships, and imputes badly, and no number anywhere says why. The
| first group below is the guard, and it is the reason the builder exists as one
| class rather than as a query written twice.
|
| **Train/serve skew.** The imputer and the trainer must assemble features
| identically or the model meets something other than what it learned. They now
| call the same method, and the last test here says so.
|
| The rest cover the four features that used to be constants — 50.0, 1.0, 1.0,
| 1.0 — which is to say a model trained on eleven signals and served seven.
|
*/

beforeEach(function (): void {
    $this->country = Country::factory()->create(['timezone' => 'UTC', 'fx_config' => ['rate_type' => 'parallel']]);
    $this->target = Location::factory()->create([
        'country_id' => $this->country->id,
        'latitude' => 32.0,
        'longitude' => 13.0,
    ]);
    $this->item = CanonicalItem::factory()->create(['country_id' => $this->country->id]);
    $this->builder = new NowcastFeatureBuilder;
});

function observe(Location $at, CanonicalItem $item, string $on, float $price): PriceObservation
{
    return PriceObservation::factory()->create([
        'country_id' => $at->country_id,
        'location_id' => $at->id,
        'canonical_item_id' => $item->id,
        'observed_on' => $on,
        'observed_at' => CarbonImmutable::parse($on),
        'normalized_price_per_base_unit' => $price,
        'is_valid' => true,
    ]);
}

function nearby(Country $country, float $lat, float $lon): Location
{
    return Location::factory()->create([
        'country_id' => $country->id,
        'latitude' => $lat,
        'longitude' => $lon,
    ]);
}

describe('what it refuses to look at', function (): void {
    it('never sees the observation it is being trained to predict', function (): void {
        // The whole basis of the training set: this row exists, and the
        // features must be assembled as though it did not.
        observe($this->target, $this->item, '2026-06-10', 999.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['last_local_price'])->toBe(0.0)
            ->and($features['national_median'])->toBe(0.0);
    });

    it('does not let this location inform the national reference for its own item', function (): void {
        // Otherwise the target's own price is inside the number the target is
        // divided by, and the ratio the model learns is partly a tautology.
        observe($this->target, $this->item, '2026-06-08', 500.0);
        observe(nearby($this->country, 32.1, 13.1), $this->item, '2026-06-08', 10.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['national_median'])->toBe(10.0);
    });

    it('reads this location history only from strictly before the date', function (): void {
        observe($this->target, $this->item, '2026-06-08', 7.0);
        observe($this->target, $this->item, '2026-06-10', 999.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['last_local_price'])->toBe(7.0)
            ->and($features['days_since_local'])->toBe(2.0);
    });

    it('cannot be moved by anything that happens afterwards', function (): void {
        // The strongest form of the guard: build the features, then add every
        // kind of future evidence, rebuild, and require the answer to be
        // identical. A lookahead path anywhere would show up as a difference.
        observe($this->target, $this->item, '2026-06-05', 6.0);
        observe(nearby($this->country, 32.1, 13.1), $this->item, '2026-06-06', 8.0);

        $asOf = CarbonImmutable::parse('2026-06-10');
        $before = $this->builder->build($this->country, $this->target, $this->item->id, $asOf);

        observe($this->target, $this->item, '2026-06-11', 4242.0);
        observe(nearby($this->country, 32.2, 13.2), $this->item, '2026-06-12', 4242.0);
        FxRate::factory()->create([
            'country_id' => $this->country->id,
            'rate_date' => '2026-06-30',
            'parallel_rate' => 99.0,
        ]);

        $after = $this->builder->build($this->country, $this->target, $this->item->id, $asOf);

        expect($after)->toBe($before);
    });
});

describe('the neighbourhood', function (): void {
    it('prefers the nearest places that actually reported', function (): void {
        // A location fifteen kilometres away that reported nothing is no help,
        // and counting it as a neighbour would understate how far the evidence
        // really travelled.
        nearby($this->country, 32.01, 13.01);                                   // near, silent
        observe(nearby($this->country, 33.0, 14.0), $this->item, '2026-06-09', 12.0);  // far, reported

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['neighbour_count'])->toBe(1.0)
            ->and($features['neighbour_median'])->toBe(12.0)
            ->and($features['nearest_neighbour_km'])->toBeGreaterThan(100.0);
    });

    it('weights closer evidence more heavily', function (): void {
        observe(nearby($this->country, 32.01, 13.01), $this->item, '2026-06-09', 10.0);
        observe(nearby($this->country, 34.0, 15.0), $this->item, '2026-06-09', 20.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        // Nearer to the close neighbour's 10 than to the midpoint of 15.
        expect($features['neighbour_weighted'])->toBeLessThan(15.0)
            ->and($features['neighbour_weighted'])->toBeGreaterThan(10.0);
    });

    it('says the neighbourhood is empty rather than inventing one', function (): void {
        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['neighbour_count'])->toBe(0.0)
            ->and($features['neighbour_median'])->toBe(0.0)
            ->and($features['nearest_neighbour_km'])->toBe(0.0);
    });
});

describe('the features that used to be constants', function (): void {
    it('measures how far the currency moved, instead of claiming it did not', function (): void {
        // Pinned at 1.0, this told the model the currency never moves — in
        // countries chosen for this platform *because* it does.
        FxRate::factory()->create([
            'country_id' => $this->country->id,
            'rate_date' => '2026-05-11',
            'parallel_rate' => 5.0,
            'is_manual' => false,
        ]);
        FxRate::factory()->create([
            'country_id' => $this->country->id,
            'rate_date' => '2026-06-10',
            'parallel_rate' => 6.0,
            'is_manual' => false,
        ]);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['fx_change_30d'])->toBe(1.2);
    });

    it('reports no currency movement when there is nothing to compare', function (): void {
        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['fx_change_30d'])->toBe(1.0);
    });

    it('measures the national trend week over week', function (): void {
        $elsewhere = nearby($this->country, 32.5, 13.5);

        observe($elsewhere, $this->item, '2026-05-30', 10.0);   // previous week
        observe($elsewhere, $this->item, '2026-06-09', 11.0);   // current week

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['national_trend'])->toBe(1.1);
    });

    it('knows whether a location runs dear or cheap', function (): void {
        // Without this the model has no way to know a remote town sits
        // consistently above the national median, and would impute it the
        // national price.
        $otherItem = CanonicalItem::factory()->create(['country_id' => $this->country->id]);
        $elsewhere = nearby($this->country, 32.5, 13.5);

        observe($this->target, $otherItem, '2026-06-05', 15.0);
        observe($elsewhere, $otherItem, '2026-06-05', 10.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['location_price_level'])->toBe(1.5);
    });

    it('excludes the item being imputed from that judgement', function (): void {
        // Otherwise the item's own history informs the feature that helps
        // impute it, which is lookahead wearing a different hat.
        observe($this->target, $this->item, '2026-06-05', 99.0);
        observe(nearby($this->country, 32.5, 13.5), $this->item, '2026-06-05', 1.0);

        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect($features['location_price_level'])->toBe(1.0);
    });
});

describe('the shape of what is sent', function (): void {
    it('produces exactly the features the model was fitted on', function (): void {
        // The ML side declares these names. A mismatch is not caught by types
        // anywhere: it arrives as a model quietly served a default.
        $features = $this->builder->build(
            $this->country,
            $this->target,
            $this->item->id,
            CarbonImmutable::parse('2026-06-10'),
        );

        expect(array_keys($features))->toBe([
            'national_median',
            'neighbour_median',
            'neighbour_weighted',
            'neighbour_count',
            'nearest_neighbour_km',
            'last_local_price',
            'days_since_local',
            'national_trend',
            'fx_change_30d',
            'location_price_level',
            'day_of_week',
        ]);

        foreach ($features as $name => $value) {
            expect($value)->toBeFloat("{$name} must be numeric for the model");
        }
    });
});
