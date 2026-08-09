<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ScoreSubmissionAnomaly;
use App\Models\AnomalyScore;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Services\Ml\FakeMlClient;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;

/*
|--------------------------------------------------------------------------
| Anomaly scoring pipeline
|--------------------------------------------------------------------------
|
| What is protected here is the difference between "judged clean" and "not yet
| judged". Treating a service outage as a clean verdict would let bad data
| through exactly when the system is least able to notice.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->item = CanonicalItem::query()->where('code', 'rice_1kg')->firstOrFail();
});

function observationAt(float $price): PriceObservation
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'status' => Submission::STATUS_RESOLVED,
    ]);

    return PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'canonical_item_id' => test()->item->id,
        'price' => $price,
        'normalized_price_per_base_unit' => $price,
        'observed_on' => now()->toDateString(),
        'observed_at' => now(),
    ]);
}

describe('recording verdicts', function () {
    it('records a verdict per observation', function () {
        $observations = collect([observationAt(6.5), observationAt(6.6)]);

        $recorded = (new ScoreSubmissionAnomaly(new FakeMlClient))->handle($observations);

        expect($recorded)->toBe(2)
            ->and(AnomalyScore::query()->count())->toBe(2);
    });

    it('leaves a clean observation valid and resolved', function () {
        $observation = observationAt(6.5);

        (new ScoreSubmissionAnomaly(new FakeMlClient))->handle(collect([$observation]));

        expect($observation->fresh()->is_valid)->toBeTrue()
            ->and($observation->submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED);
    });

    it('invalidates but does not delete a rejected observation', function () {
        // An operator overruling the detector needs the original row to exist.
        $observation = observationAt(6500.0);

        $fake = (new FakeMlClient)->willScoreAnomalies([[
            'submission_id' => (string) $observation->submission_id,
            'score' => 0.98,
            'verdict' => AnomalyScore::VERDICT_REJECTED,
            'reasons' => [['code' => 'hard_bounds_high', 'message' => 'Price is 1000x the typical price.']],
            'layer_scores' => ['bounds' => 1.0],
        ]]);

        (new ScoreSubmissionAnomaly($fake))->handle(collect([$observation]));

        expect($observation->fresh()->is_valid)->toBeFalse()
            ->and(PriceObservation::query()->count())->toBe(1)
            ->and($observation->submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
    });

    it('keeps a suspect observation valid but asks a human to look', function () {
        // Discarding on suspicion alone would silently drop genuine supply
        // shocks, which are what this platform exists to publish.
        $observation = observationAt(9.1);

        $fake = (new FakeMlClient)->willScoreAnomalies([[
            'submission_id' => (string) $observation->submission_id,
            'score' => 0.6,
            'verdict' => AnomalyScore::VERDICT_SUSPECT,
            'reasons' => [['code' => 'robust_outlier', 'message' => 'Price is 4.2 deviations above the local median.']],
            'layer_scores' => ['robust' => 0.6],
        ]]);

        (new ScoreSubmissionAnomaly($fake))->handle(collect([$observation]));

        expect($observation->fresh()->is_valid)->toBeTrue()
            ->and($observation->submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
    });

    it('stores the human-readable reasons a reviewer needs', function () {
        $observation = observationAt(6500.0);

        $fake = (new FakeMlClient)->willScoreAnomalies([[
            'submission_id' => (string) $observation->submission_id,
            'score' => 0.98,
            'verdict' => AnomalyScore::VERDICT_REJECTED,
            'reasons' => [['code' => 'hard_bounds_high', 'message' => 'Price is 1000x the recent typical price.']],
            'layer_scores' => ['bounds' => 1.0],
        ]]);

        (new ScoreSubmissionAnomaly($fake))->handle(collect([$observation]));

        $score = AnomalyScore::query()->firstOrFail();

        expect($score->reasonMessages())->toContain('Price is 1000x the recent typical price.')
            ->and($score->isActionable())->toBeTrue();
    });
});

describe('degradation', function () {
    it('records nothing rather than a clean verdict when the service is down', function () {
        // The critical distinction: unscored is not the same as clean.
        $observation = observationAt(6500.0);

        $recorded = (new ScoreSubmissionAnomaly((new FakeMlClient)->pretendUnavailable()))
            ->handle(collect([$observation]));

        expect($recorded)->toBe(0)
            ->and(AnomalyScore::query()->count())->toBe(0)
            ->and($observation->fresh()->is_valid)->toBeTrue();
    });

    it('does nothing for an empty batch', function () {
        expect((new ScoreSubmissionAnomaly(new FakeMlClient))->handle(collect()))->toBe(0);
    });

    it('ignores a verdict for an observation it did not send', function () {
        $observation = observationAt(6.5);

        $fake = (new FakeMlClient)->willScoreAnomalies([[
            'submission_id' => '00000000-0000-0000-0000-000000000000',
            'score' => 0.9,
            'verdict' => AnomalyScore::VERDICT_REJECTED,
            'reasons' => [],
            'layer_scores' => [],
        ]]);

        $recorded = (new ScoreSubmissionAnomaly($fake))->handle(collect([$observation]));

        expect($recorded)->toBe(0)
            ->and($observation->fresh()->is_valid)->toBeTrue();
    });
});

describe('context assembly', function () {
    it('sends local prices for the same item and place', function () {
        foreach ([6.4, 6.5, 6.6] as $price) {
            observationAt($price);
        }

        $target = observationAt(6.5);
        $fake = new FakeMlClient;

        (new ScoreSubmissionAnomaly($fake))->handle(collect([$target]));

        expect($fake->calls)->toHaveCount(1)
            ->and($fake->calls[0]['method'])->toBe('scoreAnomalies');
    });

    it('excludes the observation being judged from its own comparison set', function () {
        // Comparing a price against itself drags the median towards it and
        // makes an outlier look ordinary.
        $target = observationAt(100.0);
        observationAt(6.5);
        observationAt(6.6);

        $action = new ScoreSubmissionAnomaly(new FakeMlClient);
        $reflection = new ReflectionMethod($action, 'contextFor');
        /** @var array<string, mixed> $context */
        $context = $reflection->invoke($action, $target);

        expect($context['local_prices'])->not->toContain(100.0)
            ->and($context['price'])->toBe(100.0);
    });

    it('supplies a reference median derived from the item history', function () {
        foreach ([6.4, 6.5, 6.6, 6.5] as $price) {
            observationAt($price);
        }

        $target = observationAt(6.5);

        $action = new ScoreSubmissionAnomaly(new FakeMlClient);
        $context = (new ReflectionMethod($action, 'contextFor'))->invoke($action, $target);

        expect($context['item_reference_median'])->toBeFloat()
            ->and($context['item_reference_median'])->toBeGreaterThan(6.0);
    });
});
