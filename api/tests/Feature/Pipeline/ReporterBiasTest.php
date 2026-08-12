<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Services\Ml\FakeMlClient;
use App\Services\Ml\MlClientInterface;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Looking for coordinated manipulation
|--------------------------------------------------------------------------
|
| The detector behind this has existed since Phase 6, is covered by its own
| tests, and had no caller at all — so the platform's only defence against
| coordinated price manipulation was a module nothing ever ran. The synthetic
| generator has been seeding a bad-actor cluster into the demo data the whole
| time and nothing has ever looked for it.
|
| Two properties carry the whole thing:
|
| **The reference excludes the reporter being judged.** A cluster big enough to
| move a local median otherwise hides inside it — measured against a median it
| helped set, a coordinated group looks unremarkable.
|
| **Nothing is blocked automatically.** A statistical signal is a reason to
| look, not grounds to stop counting a real person's work.
|
*/

beforeEach(function (): void {
    $this->ml = new FakeMlClient;
    app()->instance(MlClientInterface::class, $this->ml);

    $this->country = Country::factory()->create(['is_active' => true, 'timezone' => 'UTC']);
    $this->location = Location::factory()->create(['country_id' => $this->country->id]);
    $this->item = CanonicalItem::factory()->create(['country_id' => $this->country->id]);
});

function reporterWithPrices(Country $country, Location $location, CanonicalItem $item, array $prices): Reporter
{
    $reporter = Reporter::factory()->create([
        'country_id' => $country->id,
        'location_id' => $location->id,
    ]);

    foreach ($prices as $price) {
        PriceObservation::factory()->create([
            'country_id' => $country->id,
            'location_id' => $location->id,
            'canonical_item_id' => $item->id,
            'reporter_id' => $reporter->id,
            'normalized_price_per_base_unit' => $price,
            'observed_on' => CarbonImmutable::now()->toDateString(),
            'is_valid' => true,
        ]);
    }

    return $reporter;
}

function biasRecords(FakeMlClient $ml): array
{
    foreach (array_reverse($ml->calls) as $call) {
        if (($call['method'] ?? null) === 'detectReporterBias') {
            return $call['records'];
        }
    }

    return [];
}

describe('what the detector is shown', function (): void {
    it('never lets a reporter be their own reference', function (): void {
        // The property the whole detector rests on. Judged against a median
        // they helped set, a coordinated cluster looks perfectly ordinary.
        $suspect = reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        $records = collect(biasRecords($this->ml))
            ->where('reporter_id', (string) $suspect->id);

        expect($records)->toHaveCount(1)
            // The others said 10 and 10. Their median is 10, and the suspect's
            // own 5 is nowhere in it.
            ->and($records->first()['reference'])->toBe(10.0)
            ->and($records->first()['price'])->toBe(5.0);
    });

    it('does not judge someone who is simply the only reporter in a place', function (): void {
        // Nobody else priced this item here, so there is nothing to be out of
        // step with. Judging them against themselves would flag whoever works
        // alone in a remote town — exactly the places this platform exists for.
        reporterWithPrices($this->country, $this->location, $this->item, [5.0, 6.0, 7.0]);

        $this->artisan('qeema:reporters:bias')
            ->expectsOutputToContain('not enough overlapping history')
            ->assertSuccessful();

        expect(biasRecords($this->ml))->toBe([]);
    });

    it('compares within a place and an item, not across them', function (): void {
        // Bread in one town and fuel in another are not evidence about each
        // other, and a reference pooled across them would flag every reporter
        // in an expensive location.
        $elsewhere = Location::factory()->create(['country_id' => $this->country->id]);

        reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [5.2]);
        $isolated = reporterWithPrices($this->country, $elsewhere, $this->item, [90.0]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        $records = collect(biasRecords($this->ml));

        // The two in one town reference each other and nothing else. The 90 in
        // the next town is not evidence about them.
        expect($records->pluck('reference')->unique()->sort()->values()->all())->toBe([5.0, 5.2])
            // And the reporter alone in the other town produces no record at
            // all: there is nobody there to be out of step with.
            ->and($records->pluck('reporter_id'))->not->toContain((string) $isolated->id);
    });
});

describe('what happens to a flagged reporter', function (): void {
    it('records the finding without acting on it', function (): void {
        $suspect = reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $this->ml->willDetectBias([[
            'reporter_id' => (string) $suspect->id,
            'n_observations' => 20,
            'lower_decile_ratio' => 0.7,
            'modified_z' => -4.2,
            'is_suspicious' => true,
            'reason' => 'Reports 30% below the local median across 20 observations.',
        ]]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        $suspect->refresh();

        expect($suspect->bias_flagged)->toBeTrue()
            ->and($suspect->bias_score)->toBe(-4.2)
            ->and($suspect->bias_reason)->toContain('30% below')
            ->and($suspect->bias_checked_at)->not->toBeNull();
    });

    it('never blocks anybody by itself', function (): void {
        // Suspending somebody's contributions on a statistical signal is a
        // judgement about a real person doing real work in a difficult place.
        // It belongs to an operator who can weigh it.
        $suspect = reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $this->ml->willDetectBias([[
            'reporter_id' => (string) $suspect->id,
            'n_observations' => 40,
            'lower_decile_ratio' => 0.5,
            'modified_z' => -9.9,
            'is_suspicious' => true,
            'reason' => 'Extreme and sustained deviation.',
        ]]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        expect($suspect->fresh()->is_blocked)->toBeFalse();
    });

    it('puts a flagged reporter in front of a human', function (): void {
        $suspect = reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $this->ml->willDetectBias([[
            'reporter_id' => (string) $suspect->id,
            'n_observations' => 20,
            'lower_decile_ratio' => 0.7,
            'modified_z' => -4.2,
            'is_suspicious' => true,
            'reason' => 'Systematically low.',
        ]]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        expect(Reporter::query()->awaitingBiasReview()->pluck('id')->all())
            ->toContain($suspect->id);
    });

    it('does not raise a reporter a human has already cleared', function (): void {
        // Otherwise the flag is re-raised every night and the queue becomes
        // noise somebody learns to dismiss.
        $suspect = reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $suspect->forceFill(['bias_cleared_at' => CarbonImmutable::now()])->save();

        $this->ml->willDetectBias([[
            'reporter_id' => (string) $suspect->id,
            'n_observations' => 20,
            'lower_decile_ratio' => 0.7,
            'modified_z' => -4.2,
            'is_suspicious' => true,
            'reason' => 'Systematically low.',
        ]]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        expect(Reporter::query()->awaitingBiasReview()->count())->toBe(0)
            ->and($suspect->fresh()->bias_flagged)->toBeTrue();
    });

    it('clears the flag when a reporter stops looking suspicious', function (): void {
        $reporter = reporterWithPrices($this->country, $this->location, $this->item, [10.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        $reporter->forceFill(['bias_flagged' => true, 'bias_reason' => 'Old finding'])->save();

        $this->ml->willDetectBias([[
            'reporter_id' => (string) $reporter->id,
            'n_observations' => 20,
            'lower_decile_ratio' => 1.0,
            'modified_z' => 0.1,
            'is_suspicious' => false,
            'reason' => 'Within the ordinary spread.',
        ]]);

        $this->artisan('qeema:reporters:bias')->assertSuccessful();

        expect($reporter->fresh()->bias_flagged)->toBeFalse()
            ->and($reporter->fresh()->bias_reason)->toBeNull();
    });

    it('says nothing rather than guessing when the detector is unavailable', function (): void {
        reporterWithPrices($this->country, $this->location, $this->item, [5.0]);
        reporterWithPrices($this->country, $this->location, $this->item, [10.0]);

        app()->instance(MlClientInterface::class, (new FakeMlClient)->pretendUnavailable());

        $this->artisan('qeema:reporters:bias')
            ->expectsOutputToContain('no opinion')
            ->assertSuccessful();

        expect(Reporter::query()->where('bias_flagged', true)->count())->toBe(0);
    });
});
