<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Jobs\ResolveSubmissionJob;
use App\Jobs\ScoreSubmissionAnomalyJob;
use App\Models\AnomalyScore;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| The reconciler
|--------------------------------------------------------------------------
|
| Dispatch-on-write is an optimisation. This is the guarantee.
|
| The failure it exists for is not hypothetical: before this phase, eleven
| submissions sat at `pending` on the running demo stack because nothing had
| ever been wired to process them. A sweeper means such a backlog needs no
| migration script — it is simply the next tick's work.
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

function strandedSubmission(int $ageSeconds): Submission
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'status' => Submission::STATUS_PENDING,
    ]);

    // created_at is what the sweeper measures, and the factory sets it to now.
    $submission->forceFill(['created_at' => CarbonImmutable::now()->subSeconds($ageSeconds)])->save();

    return $submission;
}

function unscoredObservation(int $ageHours = 0): PriceObservation
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'status' => Submission::STATUS_RESOLVED,
    ]);

    $observation = PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'canonical_item_id' => test()->item->id,
        'is_valid' => true,
    ]);

    $observation->forceFill(['created_at' => CarbonImmutable::now()->subHours($ageHours)])->save();

    return $observation;
}

it('adopts a submission the fast path never processed', function (): void {
    Queue::fake();
    $stranded = strandedSubmission(ageSeconds: 3600);

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertPushed(
        ResolveSubmissionJob::class,
        fn (ResolveSubmissionJob $job): bool => $job->submissionId === $stranded->id,
    );
});

it('leaves a submission alone while its own dispatch is still in flight', function (): void {
    Queue::fake();
    strandedSubmission(ageSeconds: 5);

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    // The age threshold is what stops the sweeper racing the dispatch that has
    // already happened for a submission written a moment ago.
    Queue::assertNotPushed(ResolveSubmissionJob::class);
});

it('adopts everything immediately when an operator says so', function (): void {
    Queue::fake();
    strandedSubmission(ageSeconds: 5);

    $this->artisan('qeema:pipeline:sweep', ['--now' => true])->assertSuccessful();

    Queue::assertPushed(ResolveSubmissionJob::class);
});

it('ignores submissions that are already resolved or reviewed', function (): void {
    Queue::fake();

    foreach ([Submission::STATUS_RESOLVED, Submission::STATUS_NEEDS_REVIEW, Submission::STATUS_REJECTED] as $status) {
        Submission::factory()->create([
            'country_id' => $this->country->id,
            'location_id' => $this->location->id,
            'status' => $status,
        ]);
    }

    $this->artisan('qeema:pipeline:sweep', ['--now' => true])->assertSuccessful();

    Queue::assertNotPushed(ResolveSubmissionJob::class);
});

it('sends work to the bulk queue so it cannot starve live submissions', function (): void {
    Queue::fake();
    strandedSubmission(ageSeconds: 3600);

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertPushed(
        ResolveSubmissionJob::class,
        fn (ResolveSubmissionJob $job): bool => $job->queue === config('qeema.pipeline.queue_bulk'),
    );
});

it('respects the dispatch limit', function (): void {
    Queue::fake();

    for ($i = 0; $i < 4; $i++) {
        strandedSubmission(ageSeconds: 3600);
    }

    $this->artisan('qeema:pipeline:sweep', ['--limit' => 2])->assertSuccessful();

    // A backlog is worked through over several ticks rather than dumped onto
    // the queue in one go.
    Queue::assertPushed(ResolveSubmissionJob::class, 2);
});

it('picks up an observation nobody screened', function (): void {
    Queue::fake();
    $observation = unscoredObservation();

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertPushed(
        ScoreSubmissionAnomalyJob::class,
        fn (ScoreSubmissionAnomalyJob $job): bool => $job->submissionId === $observation->submission_id,
    );
});

it('leaves an observation alone once it has a verdict', function (): void {
    Queue::fake();
    $observation = unscoredObservation();

    AnomalyScore::factory()->create([
        'submission_id' => $observation->submission_id,
        'verdict' => AnomalyScore::VERDICT_CLEAN,
    ]);

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertNotPushed(ScoreSubmissionAnomalyJob::class);
});

it('does not try to retro-screen history', function (): void {
    Queue::fake();

    // A seeded deployment holds tens of thousands of observations written
    // wholesale rather than through the pipeline. Without the window this
    // sweep would re-dispatch them every minute for the life of the
    // deployment — which is exactly what it did on the demo stack the first
    // time it was run.
    unscoredObservation(ageHours: 72);

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertNotPushed(ScoreSubmissionAnomalyJob::class);
});

it('ignores an observation that has been invalidated', function (): void {
    Queue::fake();
    $observation = unscoredObservation();
    $observation->forceFill(['is_valid' => false])->save();

    $this->artisan('qeema:pipeline:sweep')->assertSuccessful();

    Queue::assertNotPushed(ScoreSubmissionAnomalyJob::class);
});

it('says so plainly when there is nothing to do', function (): void {
    Queue::fake();

    $this->artisan('qeema:pipeline:sweep')
        ->expectsOutputToContain('Nothing to sweep.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
