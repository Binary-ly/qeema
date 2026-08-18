<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ScoreSubmissionAnomaly;
use App\Jobs\ScoreSubmissionAnomalyJob;
use App\Models\AnomalyScore;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Submission;
use App\Services\Ml\FakeMlClient;
use App\Services\Ml\MlClientInterface;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The whole journey
|--------------------------------------------------------------------------
|
| One test, and the only one that would have caught the gap this phase exists to
| close. Every stage was covered before — matching, normalisation, screening,
| estimation, imputation, the API contract — and a price posted to the public
| API still went nowhere, because nothing joined them up.
|
| A test of a stage cannot see a missing wire. So this walks the wire:
|
|   published snapshot -> POST a price -> resolved -> observed -> screened
|   -> snapshot marked stale -> recomputed -> visible on the public API
|
| The queue runs synchronously here on purpose. The rest of the suite discards
| queued work so that unrelated tests do not silently execute the pipeline; this
| one is about the pipeline, so it opts in.
|
*/

beforeEach(function (): void {
    config()->set('queue.default', 'sync');

    // Disclose exact observation counts here. The public API withholds counts
    // below a threshold so that a figure resting on one person's report does
    // not say so in public — but this test proves a single price completes the
    // journey, and the count is how it sees that happen. The withholding rule
    // itself is covered in tests/Feature/Api/PublicApiTest.php, where it is the
    // subject rather than an obstacle.
    config()->set('qeema.privacy.min_disclosed_observations', 1);

    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->item = CanonicalItem::query()->where('code', 'rice_1kg')->firstOrFail();

    // The matcher is faked, not the wiring. What is under test is whether the
    // stages are joined, not whether the model is good.
    app()->instance(
        MlClientInterface::class,
        (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.96),
    );
});

/**
 * Today, where the country is — which is the date the platform publishes for.
 */
function publishedDate(): string
{
    return CarbonImmutable::now(test()->country->timezone)->toDateString();
}

it('carries a submitted price all the way to the published index', function (): void {
    // 1. The state of the world before: today is published, and nobody has
    //    reported this item here.
    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    // The country's calendar day, not the server's. `qeema:index:publish`
    // deliberately works in each country's own timezone, so asking for
    // `now()` here fails for the hours when the two disagree — which is how
    // this test failed at 22:29 UTC, when Tripoli was already tomorrow.
    $date = publishedDate();
    $before = $this->getJson("/api/v1/locations/{$this->location->slug}/index/{$date}");
    $before->assertOk();

    $riceBefore = collect($before->json('data.items'))
        ->firstWhere('item.code', 'rice_1kg');

    // Absent rather than zero: an item with neither an observation nor a usable
    // imputation gets no row at all, and its weight counts against coverage.
    expect($riceBefore['observation_count'] ?? 0)->toBe(0);

    // 2. A reporter standing in a market sends one price.
    $response = $this->postJson('/api/v1/submissions', [
        'reporter_ref' => (string) Str::uuid(),
        'country' => 'LY',
        'location_slug' => $this->location->slug,
        'item_text' => 'أرز',
        'price' => 9.75,
        'unit' => 'kg',
        'quantity' => 1,
        'client_idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertCreated();

    // 3. Without anyone running a command, it has been resolved and screened.
    $submission = Submission::query()->firstOrFail();

    expect($submission->status)->toBe(Submission::STATUS_RESOLVED);

    $observation = PriceObservation::query()->where('submission_id', $submission->id)->firstOrFail();

    expect($observation->is_valid)->toBeTrue()
        ->and($observation->canonical_item_id)->toBe($this->item->id)
        ->and(AnomalyScore::query()->where('submission_id', $submission->id)->exists())->toBeTrue();

    // 4. The observation invalidated the published figure that predates it.
    $snapshot = IndexSnapshot::query()
        ->where('location_id', $this->location->id)
        ->whereDate('snapshot_date', $date)
        ->firstOrFail();

    expect($snapshot->is_stale)->toBeTrue()
        ->and($snapshot->stale_marked_at)->not->toBeNull();

    // 5. The scheduled drain republishes it. Grace zero because the test is not
    //    waiting a minute to prove a point the grace test already makes.
    $this->artisan('qeema:index', ['--grace' => 0])->assertSuccessful();

    // 6. And the public API — the product — now reflects the price.
    $after = $this->getJson("/api/v1/locations/{$this->location->slug}/index/{$date}");
    $after->assertOk();

    $riceAfter = collect($after->json('data.items'))->firstWhere('item.code', 'rice_1kg');

    expect($riceAfter['observation_count'])->toBe(1)
        ->and($riceAfter['is_imputed'])->toBeFalse()
        ->and((float) $riceAfter['unit_price'])->toBe(9.75)
        ->and($after->json('data.quality.coverage'))->toBeGreaterThan($before->json('data.quality.coverage'));

    expect($riceAfter)->toDeclareImputationStatus();
});

it('publishes a price nobody dispatched, because the sweeper finds it', function (): void {
    // The same journey for a submission written by something that fires no
    // model event — a partner import, or any future writer nobody has thought
    // of yet. This is the property that makes the pipeline dependable rather
    // than merely present.
    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    $submission = Submission::factory()->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'raw_text' => 'أرز',
        'raw_price' => 11.25,
        'raw_unit' => 'kg',
        'raw_quantity' => 1,
        'currency_code' => 'LYD',
        'observed_at' => now(),
        'status' => Submission::STATUS_PENDING,
    ]);

    $this->artisan('qeema:pipeline:sweep', ['--now' => true])->assertSuccessful();

    expect($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED);

    $this->artisan('qeema:index', ['--grace' => 0])->assertSuccessful();

    $date = publishedDate();
    $rice = collect($this->getJson("/api/v1/locations/{$this->location->slug}/index/{$date}")->json('data.items'))
        ->firstWhere('item.code', 'rice_1kg');

    expect($rice['observation_count'])->toBe(1)
        ->and($rice['is_imputed'])->toBeFalse();
});

it('never publishes a price the detector rejected', function (): void {
    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    // A decimal slip: the price is a hundred times what it should be. The
    // screening step exists precisely so this cannot reach a published figure.
    app()->instance(MlClientInterface::class, (new FakeMlClient)
        ->willMatch($this->item->id, 'rice_1kg', 0.96)
        ->willScoreAnomalies([[
            'submission_id' => 'replaced-below',
            'score' => 0.99,
            'verdict' => AnomalyScore::VERDICT_REJECTED,
            'reasons' => ['decimal_slip'],
        ]]));

    $this->postJson('/api/v1/submissions', [
        'reporter_ref' => (string) Str::uuid(),
        'country' => 'LY',
        'location_slug' => $this->location->slug,
        'item_text' => 'أرز',
        'price' => 975.0,
        'unit' => 'kg',
        'quantity' => 1,
        'client_idempotency_key' => (string) Str::uuid(),
    ])->assertCreated();

    $submission = Submission::query()->firstOrFail();

    // The verdict is keyed by submission id, which is only known after the
    // fact, so re-run screening with the real id now that it exists.
    app()->instance(MlClientInterface::class, (new FakeMlClient)->willScoreAnomalies([[
        'submission_id' => (string) $submission->id,
        'score' => 0.99,
        'verdict' => AnomalyScore::VERDICT_REJECTED,
        'reasons' => ['decimal_slip'],
    ]]));

    AnomalyScore::query()->where('submission_id', $submission->id)->delete();

    (new ScoreSubmissionAnomalyJob($submission->id))
        ->handle(app(ScoreSubmissionAnomaly::class));

    $this->artisan('qeema:index', ['--grace' => 0])->assertSuccessful();

    $date = publishedDate();
    $rice = collect($this->getJson("/api/v1/locations/{$this->location->slug}/index/{$date}")->json('data.items'))
        ->firstWhere('item.code', 'rice_1kg');

    // Invalidated, not deleted — and never counted as an observation.
    expect(PriceObservation::query()->firstOrFail()->is_valid)->toBeFalse()
        ->and($rice['observation_count'] ?? 0)->toBe(0)
        ->and(Submission::query()->firstOrFail()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});
