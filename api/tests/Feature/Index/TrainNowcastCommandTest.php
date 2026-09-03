<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Services\Ml\FakeMlClient;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Teaching the model
|--------------------------------------------------------------------------
|
| Nothing in this platform ever called the training endpoint, so the quantile
| models were never fitted in a deployment and every imputed price came from a
| ±30% heuristic. The model card described a component no running system had
| used.
|
| What matters most here is not that training happens but *what it is taught*:
| a target expressed as a ratio, and features assembled as though the target had
| never been observed.
|
*/

beforeEach(function (): void {
    $this->ml = new FakeMlClient;
    app()->instance(MlClientInterface::class, $this->ml);

    $this->country = Country::factory()->create(['timezone' => 'UTC', 'is_active' => true]);
    $this->location = Location::factory()->create([
        'country_id' => $this->country->id,
        'latitude' => 32.0,
        'longitude' => 13.0,
    ]);
    $this->elsewhere = Location::factory()->create([
        'country_id' => $this->country->id,
        'latitude' => 32.5,
        'longitude' => 13.5,
    ]);
    $this->item = CanonicalItem::factory()->create(['country_id' => $this->country->id]);
});

function trainingObservation(Location $at, CanonicalItem $item, string $on, float $price): void
{
    PriceObservation::factory()->create([
        'country_id' => $at->country_id,
        'location_id' => $at->id,
        'canonical_item_id' => $item->id,
        'observed_on' => $on,
        'observed_at' => CarbonImmutable::parse($on),
        'normalized_price_per_base_unit' => $price,
        'is_valid' => true,
    ]);
}

function lastTrainingCall(FakeMlClient $ml): ?array
{
    foreach (array_reverse($ml->calls) as $call) {
        if (($call['method'] ?? null) === 'trainNowcast') {
            return $call;
        }
    }

    return null;
}

it('looks back as far as the country says, not as far as the command assumes', function (): void {
    // A monthly survey puts four rows per series into 120 days, and the model
    // declines on so few. The live deployment sat on a year of such rows and
    // trained on none of them, because the horizon was the command's default
    // rather than the country's.
    $lastWinter = CarbonImmutable::now()->subDays(200)->toDateString();
    trainingObservation($this->elsewhere, $this->item, $lastWinter, 10.0);
    trainingObservation($this->location, $this->item, $lastWinter, 15.0);

    $this->artisan('qeema:nowcast:train', ['--country' => $this->country->code])
        ->expectsOutputToContain('not enough history');

    expect(lastTrainingCall($this->ml))->toBeNull();

    $this->country->update(['index_config' => ['nowcast_training_days' => 365]]);

    $this->artisan('qeema:nowcast:train', ['--country' => $this->country->code]);

    expect(lastTrainingCall($this->ml)['count'])->toBe(2);
});

it('sends the target as a ratio to the national reference', function (): void {
    $today = CarbonImmutable::now()->toDateString();

    // 15 here against 10 elsewhere: the ratio is 1.5, and the ratio is what
    // makes one model serve every item at every price scale.
    trainingObservation($this->elsewhere, $this->item, $today, 10.0);
    trainingObservation($this->location, $this->item, $today, 15.0);

    $this->artisan('qeema:nowcast:train')->assertSuccessful();

    $call = lastTrainingCall($this->ml);

    expect($call)->not->toBeNull()
        ->and($call['targets'])->toContain(1.5);
});

it('never puts the target price into the features that predict it', function (): void {
    $today = CarbonImmutable::now()->toDateString();

    trainingObservation($this->elsewhere, $this->item, $today, 10.0);
    trainingObservation($this->location, $this->item, $today, 999.0);

    $this->artisan('qeema:nowcast:train')->assertSuccessful();

    $call = lastTrainingCall($this->ml);

    // A feature carrying 999 would let the model read the answer off its own
    // input: beautiful in evaluation, useless in service, and silent about it.
    expect(json_encode($call))->not->toContain('999');
});

it('skips a row with no national reference to divide by', function (): void {
    // Only this location reported, so there is nothing to express the target
    // as a ratio of. A row like this cannot be turned into training data
    // honestly, so it is dropped rather than defaulted.
    trainingObservation($this->location, $this->item, CarbonImmutable::now()->toDateString(), 15.0);

    $this->artisan('qeema:nowcast:train')
        ->expectsOutputToContain('not enough history')
        ->assertSuccessful();

    expect(lastTrainingCall($this->ml))->toBeNull();
});

it('waits rather than training against an unavailable service', function (): void {
    app()->instance(MlClientInterface::class, (new FakeMlClient)->pretendUnavailable());

    $this->artisan('qeema:nowcast:train')
        ->expectsOutputToContain('unavailable')
        ->assertSuccessful();
});

it('only draws on history inside the window it was asked for', function (): void {
    $today = CarbonImmutable::now();

    trainingObservation($this->elsewhere, $this->item, $today->toDateString(), 10.0);
    trainingObservation($this->location, $this->item, $today->toDateString(), 15.0);

    // A year ago, as a pair too. Without the second one the old rows would be
    // dropped for having no national reference rather than for being old, and
    // the test would pass while proving nothing about the window.
    trainingObservation($this->elsewhere, $this->item, $today->subYear()->toDateString(), 10.0);
    trainingObservation($this->location, $this->item, $today->subYear()->toDateString(), 15.0);

    // Both of a day's observations are legitimate training rows — each is the
    // other's national reference.
    $this->artisan('qeema:nowcast:train', ['--days' => 30])->assertSuccessful();
    $inWindow = lastTrainingCall($this->ml)['count'];

    $this->artisan('qeema:nowcast:train', ['--days' => 400])->assertSuccessful();
    $everything = lastTrainingCall($this->ml)['count'];

    expect($inWindow)->toBe(2)
        ->and($everything)->toBe(4);
});

it('can be restricted to one country', function (): void {
    $other = Country::factory()->create(['is_active' => true, 'code' => 'ZZ']);

    $this->artisan('qeema:nowcast:train', ['--country' => $other->code])->assertSuccessful();

    // The country under test has observations; the one asked for does not.
    expect(lastTrainingCall($this->ml))->toBeNull();
});

it('trains each country against its own model', function (): void {
    // The service keeps one fitted model per country. Before it did not, and
    // training two countries left the second one answering for both — with
    // plausible numbers, which is why nothing caught it.
    $other = Country::factory()->create(['is_active' => true, 'code' => 'ZY', 'timezone' => 'UTC']);
    $otherLocation = Location::factory()->create(['country_id' => $other->id, 'latitude' => 10.0, 'longitude' => 10.0]);
    $otherElsewhere = Location::factory()->create(['country_id' => $other->id, 'latitude' => 10.5, 'longitude' => 10.5]);
    $otherItem = CanonicalItem::factory()->create(['country_id' => $other->id]);

    $today = CarbonImmutable::now()->toDateString();
    trainingObservation($this->elsewhere, $this->item, $today, 10.0);
    trainingObservation($this->location, $this->item, $today, 15.0);
    trainingObservation($otherElsewhere, $otherItem, $today, 10.0);
    trainingObservation($otherLocation, $otherItem, $today, 15.0);

    $this->artisan('qeema:nowcast:train')->assertSuccessful();

    $countries = collect($this->ml->calls)
        ->where('method', 'trainNowcast')
        ->pluck('country')
        ->sort()
        ->values()
        ->all();

    expect($countries)->toBe(collect([$this->country->code, $other->code])->sort()->values()->all());
});
