<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ResolveSubmission;
use App\Jobs\ResolveSubmissionJob;
use App\Jobs\ScoreSubmissionAnomalyJob;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use App\Services\Ml\FakeMlClient;
use App\Services\Ml\MatchResult;
use App\Services\Ml\MlClientInterface;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| The wire that was missing
|--------------------------------------------------------------------------
|
| Before this job existed, a price posted to the public API was written with
| status `pending` and stayed there permanently: every stage of the pipeline was
| built and tested, and nothing ever called any of it.
|
| These tests are about the three properties that make the wire safe to rely on
| rather than merely present — that running it twice is harmless, that an outage
| is waited out rather than converted into human work, and that nothing ever
| ends in silence.
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

function pendingSubmission(array $attributes = []): Submission
{
    return Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'reporter_id' => Reporter::factory()->create([
            'country_id' => test()->country->id,
            'location_id' => test()->location->id,
        ])->id,
        'source_id' => Source::factory()->create(['country_id' => test()->country->id])->id,
        'raw_text' => 'أرز',
        'raw_unit' => 'kg',
        'raw_quantity' => 1,
        'currency_code' => 'LYD',
        'status' => Submission::STATUS_PENDING,
        ...$attributes,
    ]);
}

function runResolution(Submission $submission, FakeMlClient $ml): ResolveSubmissionJob
{
    app()->instance(MlClientInterface::class, $ml);

    $job = (new ResolveSubmissionJob($submission->id))->withFakeQueueInteractions();
    $job->handle(new ResolveSubmission($ml), $ml);

    return $job;
}

/**
 * A matcher that is reachable and then throws.
 *
 * Distinct from `pretendUnavailable()` on purpose: an unreachable service is an
 * outage to be waited out, while an exception is a bug to be surfaced. The two
 * take different paths through the job, and conflating them is how a real
 * defect gets retried quietly for ever.
 */
function explodingMatcher(): MlClientInterface&MockInterface
{
    $ml = Mockery::mock(MlClientInterface::class);
    $ml->shouldReceive('isAvailable')->andReturnTrue();
    $ml->shouldReceive('match')->andThrow(new RuntimeException('matcher exploded'));

    return $ml;
}

it('turns a pending submission into a valid observation', function (): void {
    Queue::fake();
    $submission = pendingSubmission();

    runResolution($submission, (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95));

    expect($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED);

    $observation = PriceObservation::query()->where('submission_id', $submission->id)->first();

    expect($observation)->not->toBeNull()
        ->and($observation->is_valid)->toBeTrue()
        ->and((float) $observation->normalized_price_per_base_unit)->toBeGreaterThan(0.0);

    // Screening is a separate job so that a transient scoring failure does not
    // re-run a resolution that already succeeded.
    Queue::assertPushed(ScoreSubmissionAnomalyJob::class);
});

it('does nothing to a submission that is no longer pending', function (): void {
    Queue::fake();
    $submission = pendingSubmission(['status' => Submission::STATUS_RESOLVED]);

    runResolution($submission, (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg'));

    // The guard that makes double dispatch, sweeper overlap and queue retries
    // all harmless.
    expect(PriceObservation::query()->count())->toBe(0)
        ->and(Resolution::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('does nothing when the submission has been deleted', function (): void {
    Queue::fake();
    $submission = pendingSubmission();
    $id = $submission->id;
    $submission->delete();

    app()->instance(MlClientInterface::class, $ml = new FakeMlClient);
    (new ResolveSubmissionJob($id))->withFakeQueueInteractions()->handle(new ResolveSubmission($ml), $ml);

    expect(PriceObservation::query()->count())->toBe(0);
});

it('produces exactly one observation when it runs twice', function (): void {
    Queue::fake();
    $submission = pendingSubmission();
    $ml = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95);

    runResolution($submission, $ml);
    runResolution($submission->fresh(), $ml);

    // Belt and braces: the status guard stops the second run, and
    // price_observations.submission_id is UNIQUE if it ever did not.
    expect(PriceObservation::query()->where('submission_id', $submission->id)->count())->toBe(1);
});

it('waits out a matching outage instead of filling the review queue', function (): void {
    Queue::fake();
    $submission = pendingSubmission();

    $job = runResolution($submission, (new FakeMlClient)->pretendUnavailable());

    // The whole point: a container restart must not convert a thousand
    // automatic matches into a thousand items of human work.
    expect($submission->fresh()->status)->toBe(Submission::STATUS_PENDING)
        ->and($submission->fresh()->pipeline_attempts)->toBe(1)
        ->and(Resolution::query()->count())->toBe(0);

    $job->assertReleased(delay: 10);
});

it('escalates the delay as an outage continues', function (): void {
    Queue::fake();
    $submission = pendingSubmission(['pipeline_attempts' => 2]);

    $job = runResolution($submission, (new FakeMlClient)->pretendUnavailable());

    // Third attempt on the ladder [10, 30, 120, 300].
    $job->assertReleased(delay: 120);
});

it('hands the submission to a human once the outage outlasts the budget', function (): void {
    Queue::fake();
    config()->set('qeema.pipeline.max_attempts', 3);
    $submission = pendingSubmission(['pipeline_attempts' => 2]);

    $job = runResolution($submission, (new FakeMlClient)->pretendUnavailable());

    // Waiting for ever is not an option either: the last attempt falls through
    // and lets ResolveSubmission route it honestly.
    expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
        ->and($submission->fresh()->pipeline_attempts)->toBe(3);

    $job->assertNotReleased();

    $resolution = Resolution::query()->where('submission_id', $submission->id)->firstOrFail();
    expect($resolution->notes)->toContain('unavailable');
});

it('sends a low-confidence match to review rather than guessing', function (): void {
    Queue::fake();
    $submission = pendingSubmission();

    runResolution($submission, (new FakeMlClient)->willMatch(
        $this->item->id,
        'rice_1kg',
        confidence: 0.4,
        action: MatchResult::ACTION_REVIEW,
    ));

    expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
        ->and(PriceObservation::query()->count())->toBe(0);

    // No observation means nothing to screen.
    Queue::assertNotPushed(ScoreSubmissionAnomalyJob::class);
});

it('records the error and retries when resolution throws', function (): void {
    Queue::fake();
    $submission = pendingSubmission();

    $ml = explodingMatcher();

    app()->instance(MlClientInterface::class, $ml);
    $job = (new ResolveSubmissionJob($submission->id))->withFakeQueueInteractions();

    // Rethrown rather than swallowed, so the failure is visible in Horizon
    // rather than being quietly absorbed by the pipeline.
    expect(fn () => $job->handle(new ResolveSubmission($ml), $ml))
        ->toThrow(RuntimeException::class);

    expect($submission->fresh()->pipeline_attempts)->toBe(1)
        ->and($submission->fresh()->pipeline_last_error)->toContain('matcher exploded')
        ->and($submission->fresh()->status)->toBe(Submission::STATUS_PENDING);
});

it('hands a persistently failing submission to a human with the error attached', function (): void {
    Queue::fake();
    config()->set('qeema.pipeline.max_attempts', 2);
    $submission = pendingSubmission(['pipeline_attempts' => 1]);

    $ml = explodingMatcher();

    app()->instance(MlClientInterface::class, $ml);

    // No exception this time: the budget is gone, so the terminal state is a
    // row in the review queue rather than another retry.
    (new ResolveSubmissionJob($submission->id))
        ->withFakeQueueInteractions()
        ->handle(new ResolveSubmission($ml), $ml);

    $submission->refresh();

    expect($submission->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
        ->and($submission->pipeline_attempts)->toBe(2);

    $resolution = Resolution::query()->where('submission_id', $submission->id)->firstOrFail();

    expect($resolution->notes)->toContain('matcher exploded')
        ->and($resolution->reviewed)->toBeFalse();
});

it('routes to review if the job dies without the handler noticing', function (): void {
    $submission = pendingSubmission();

    // A timeout that killed the process, or anything else the guarded block
    // never saw. Silence is the one outcome that is not allowed.
    (new ResolveSubmissionJob($submission->id))->failed(new RuntimeException('worker died'));

    expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('leaves an already-resolved submission alone when a late failure arrives', function (): void {
    $submission = pendingSubmission(['status' => Submission::STATUS_RESOLVED]);

    (new ResolveSubmissionJob($submission->id))->failed(new RuntimeException('worker died'));

    expect($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED)
        ->and(Resolution::query()->count())->toBe(0);
});

it('runs on the live queue, not behind a bulk import', function (): void {
    $job = new ResolveSubmissionJob('00000000-0000-0000-0000-000000000000');

    expect($job->queue)->toBe(config('qeema.pipeline.queue_live'));
});
