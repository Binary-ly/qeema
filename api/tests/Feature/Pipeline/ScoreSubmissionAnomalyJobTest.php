<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ScoreSubmissionAnomaly;
use App\Jobs\ScoreSubmissionAnomalyJob;
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
| Screening, as its own step
|--------------------------------------------------------------------------
|
| Kept separate from resolution because the two are wrong in different ways and
| recover differently. The property that matters most here is the one that looks
| like doing nothing: when the detector has no opinion, no verdict is recorded.
| A fabricated "clean" would let bad data through at exactly the moment the
| system is least able to notice.
|
*/

beforeEach(function (): void {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->item = CanonicalItem::query()->where('code', 'rice_1kg')->firstOrFail();
});

function observationToScore(): PriceObservation
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
        'is_valid' => true,
    ]);
}

it('records a verdict for an observation', function (): void {
    $observation = observationToScore();

    (new ScoreSubmissionAnomalyJob($observation->submission_id))
        ->handle(new ScoreSubmissionAnomaly(new FakeMlClient));

    $score = AnomalyScore::query()->where('submission_id', $observation->submission_id)->first();

    expect($score)->not->toBeNull()
        ->and($score->verdict)->toBe(AnomalyScore::VERDICT_CLEAN);
});

it('does not score the same submission twice', function (): void {
    $observation = observationToScore();
    $ml = new FakeMlClient;

    (new ScoreSubmissionAnomalyJob($observation->submission_id))->handle(new ScoreSubmissionAnomaly($ml));
    (new ScoreSubmissionAnomalyJob($observation->submission_id))->handle(new ScoreSubmissionAnomaly($ml));

    // A second verdict would stack on the first and, where the first was a
    // rejection, could flip a deliberately invalidated observation back in.
    expect(AnomalyScore::query()->where('submission_id', $observation->submission_id)->count())->toBe(1);
});

it('does nothing when there is no observation to judge', function (): void {
    $submission = Submission::factory()->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'status' => Submission::STATUS_NEEDS_REVIEW,
    ]);

    (new ScoreSubmissionAnomalyJob($submission->id))
        ->handle(new ScoreSubmissionAnomaly(new FakeMlClient));

    expect(AnomalyScore::query()->count())->toBe(0);
});

it('records nothing at all when the detector has no opinion', function (): void {
    $observation = observationToScore();

    (new ScoreSubmissionAnomalyJob($observation->submission_id))
        ->handle(new ScoreSubmissionAnomaly((new FakeMlClient)->pretendUnavailable()));

    // Left unscored deliberately, for the sweeper to retry. Inventing a clean
    // verdict here would be worse than the gap it papers over.
    expect(AnomalyScore::query()->count())->toBe(0)
        ->and($observation->fresh()->is_valid)->toBeTrue();
});

it('invalidates an observation the detector rejects', function (): void {
    $observation = observationToScore();

    $ml = (new FakeMlClient)->willScoreAnomalies([[
        'submission_id' => (string) $observation->submission_id,
        'score' => 0.97,
        'verdict' => AnomalyScore::VERDICT_REJECTED,
        'reasons' => ['decimal_slip'],
    ]]);

    (new ScoreSubmissionAnomalyJob($observation->submission_id))->handle(new ScoreSubmissionAnomaly($ml));

    // Invalidated rather than deleted: the provenance chain has to survive a
    // machine decision exactly as it survives a human one.
    expect($observation->fresh()->is_valid)->toBeFalse()
        ->and(Submission::query()->find($observation->submission_id)->status)
        ->toBe(Submission::STATUS_NEEDS_REVIEW);
});
