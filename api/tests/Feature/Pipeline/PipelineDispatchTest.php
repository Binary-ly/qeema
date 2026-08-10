<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\RecordSubmission;
use App\Jobs\ResolveIngestionBatchJob;
use App\Jobs\ResolveSubmissionJob;
use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Source;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Ingestion\ColumnMapping;
use App\Support\Ingestion\PartnerFileImporter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The fast path
|--------------------------------------------------------------------------
|
| Dispatch-on-write is what makes the platform feel live; the sweeper is what
| makes it reliable. These tests cover the first, and in particular the two
| places a submission can be written — the public API and a partner
| spreadsheet — because the importer writes with the query builder and fires no
| model events, which is exactly how a "fixed" pipeline stays broken for bulk
| data.
|
*/

beforeEach(function (): void {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
});

function submissionInput(array $overrides = []): array
{
    return [
        'reporter_ref' => (string) Str::uuid(),
        'country' => 'LY',
        'location_slug' => test()->location->slug,
        'item_text' => 'أرز',
        'price' => 12.5,
        'client_idempotency_key' => (string) Str::uuid(),
        ...$overrides,
    ];
}

it('hands a newly accepted submission to the pipeline', function (): void {
    Queue::fake();

    $result = (new RecordSubmission)->handle(submissionInput());

    Queue::assertPushed(
        ResolveSubmissionJob::class,
        fn (ResolveSubmissionJob $job): bool => $job->submissionId === $result->submission->id,
    );
});

it('puts live submissions on the live queue', function (): void {
    Queue::fake();

    (new RecordSubmission)->handle(submissionInput());

    Queue::assertPushed(
        ResolveSubmissionJob::class,
        fn (ResolveSubmissionJob $job): bool => $job->queue === config('qeema.pipeline.queue_live'),
    );
});

it('does not re-enter the pipeline when an offline queue replays a submission', function (): void {
    $input = submissionInput();
    (new RecordSubmission)->handle($input);

    // The replay, after the first has already been recorded.
    Queue::fake();
    $result = (new RecordSubmission)->handle($input);

    expect($result->isDuplicate())->toBeTrue();

    // Re-dispatching would put work that is already done ahead of work that is
    // still waiting.
    Queue::assertNothingPushed();
});

it('does not dispatch for a blocked reporter', function (): void {
    Queue::fake();
    $input = submissionInput();

    (new RecordSubmission)->handle($input);
    $reporter = Reporter::query()->where('external_ref', $input['reporter_ref'])->firstOrFail();
    $reporter->forceFill(['is_blocked' => true])->save();

    Queue::fake();
    $result = (new RecordSubmission)->handle(submissionInput(['reporter_ref' => $input['reporter_ref']]));

    expect($result->isRejected())->toBeTrue();
    Queue::assertNothingPushed();
});

it('hands an imported partner file to the pipeline', function (): void {
    Queue::fake();

    $source = Source::query()
        ->where('country_id', $this->country->id)
        ->where('type', Source::TYPE_PARTNER_UPLOAD)
        ->firstOrFail();

    $path = sys_get_temp_dir().'/qeema-pipeline-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, "Product,Price,Market,Unit,Date\nRice,6.50,{$this->location->name},kg,2026-08-01\n");

    $batch = (new PartnerFileImporter)->import(
        source: $source,
        path: $path,
        mapping: ColumnMapping::fromArray([
            'item' => 'Product',
            'price' => 'Price',
            'location' => 'Market',
            'unit' => 'Unit',
            'observed_at' => 'Date',
        ]),
        originalFilename: 'partner.csv',
    );

    expect($batch->accepted_count)->toBe(1);

    Queue::assertPushed(
        ResolveIngestionBatchJob::class,
        fn (ResolveIngestionBatchJob $job): bool => $job->ingestionBatchId === $batch->id,
    );

    unlink($path);
});

it('fans a batch out onto the bulk queue so live reporters are not stuck behind it', function (): void {
    $batch = IngestionBatch::factory()->create([
        'source_id' => Source::factory()->create(['country_id' => $this->country->id])->id,
    ]);

    $submissions = Submission::factory()->count(3)->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'ingestion_batch_id' => $batch->id,
        'status' => Submission::STATUS_PENDING,
    ]);

    // Already dealt with: fanning it out again would be wasted work.
    Submission::factory()->create([
        'country_id' => $this->country->id,
        'location_id' => $this->location->id,
        'ingestion_batch_id' => $batch->id,
        'status' => Submission::STATUS_RESOLVED,
    ]);

    Queue::fake();

    (new ResolveIngestionBatchJob($batch->id))->handle();

    Queue::assertPushed(ResolveSubmissionJob::class, 3);
    Queue::assertPushed(
        ResolveSubmissionJob::class,
        fn (ResolveSubmissionJob $job): bool => $job->queue === config('qeema.pipeline.queue_bulk'),
    );

    expect($submissions)->toHaveCount(3);
});
