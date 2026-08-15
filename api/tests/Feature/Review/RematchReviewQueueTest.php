<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use App\Models\Unit;
use App\Support\Text\TextNormalizer;

/**
 * The review queue is a snapshot of what the matcher could not resolve at the
 * time. Growing the catalogue changes that, and nothing went back for the rows
 * already waiting: adding nine items to Libya left 26,937 queued submissions
 * carrying text that had just become catalogue vocabulary.
 */
beforeEach(function (): void {
    $this->country = Country::factory()->create(['code' => 'XX', 'currency_code' => 'XXD', 'is_active' => true]);

    Unit::query()->create([
        'country_id' => $this->country->id, 'code' => 'l', 'name' => 'Litre',
        'dimension' => 'volume', 'base_unit_code' => 'l', 'factor_to_base' => 1,
    ]);

    $this->item = CanonicalItem::query()->create([
        'country_id' => $this->country->id, 'code' => 'olive_oil_1l', 'name_en' => 'Olive oil',
        'category' => 'staples', 'default_unit_code' => 'l', 'default_quantity' => 1,
        'is_active' => true,
    ]);

    $this->location = Location::factory()->create(['country_id' => $this->country->id]);
    $this->reporter = Reporter::factory()->create(['country_id' => $this->country->id]);
    $this->source = Source::factory()->create(['country_id' => $this->country->id]);

    $this->queue = function (string $text): Submission {
        $submission = new Submission;

        $submission->forceFill([
            'country_id' => $this->country->id,
            'location_id' => $this->location->id,
            'reporter_id' => $this->reporter->id,
            'source_id' => $this->source->id,
            'raw_text' => $text,
            'raw_price' => 12.0,
            'raw_unit' => 'l',
            'currency_code' => 'XXD',
            'observed_at' => now(),
            'collected_at' => now(),
            'ingested_at' => now(),
            'status' => Submission::STATUS_NEEDS_REVIEW,
        ])->save();

        return $submission->refresh();
    };

    $this->catalogue = function (string $text): void {
        CanonicalItemVariant::query()->create([
            'canonical_item_id' => $this->item->id,
            'text' => $text,
            'normalized_text' => app(TextNormalizer::class)->normalize($text),
            'source' => CanonicalItemVariant::SOURCE_SEED,
        ]);
    };
});

it('resolves a queued submission once its text becomes catalogue vocabulary', function (): void {
    $submission = ($this->queue)('زيت زيتون بكر لتر');
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])->assertSuccessful();

    expect($submission->refresh()->status)->toBe(Submission::STATUS_RESOLVED)
        ->and($submission->resolution->canonical_item_id)->toBe($this->item->id)
        ->and($submission->resolution->method)->toBe(Resolution::METHOD_EXACT);
});

it('creates the price observation, so the price actually reaches the index', function (): void {
    // Resolving the text without producing an observation would move the row
    // out of the queue and lose the price, which is worse than leaving it.
    $submission = ($this->queue)('زيت زيتون بكر لتر');
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])->assertSuccessful();

    expect($submission->refresh()->priceObservation)->not->toBeNull();
});

it('leaves a submission whose text is still unknown', function (): void {
    $submission = ($this->queue)('حاجه ما نعرفهاش');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])->assertSuccessful();

    expect($submission->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('resolves only on an exact match, never on a near one', function (): void {
    // The whole safety of this command is that it makes no judgement. A row it
    // would have to guess about is a row a human should see.
    $submission = ($this->queue)('زيت زيتون بكر لتر ممتاز جدا');
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])->assertSuccessful();

    expect($submission->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('changes nothing on a dry run', function (): void {
    $submission = ($this->queue)('زيت زيتون بكر لتر');
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX', '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($submission->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('does not touch another country', function (): void {
    $elsewhere = Country::factory()->create(['code' => 'YY', 'currency_code' => 'YYD', 'is_active' => true]);
    $submission = ($this->queue)('زيت زيتون بكر لتر');
    $submission->forceFill(['country_id' => $elsewhere->id])->save();
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])->assertSuccessful();

    expect($submission->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('says the index needs recomputing, because new observations move it', function (): void {
    ($this->queue)('زيت زيتون بكر لتر');
    ($this->catalogue)('زيت زيتون بكر لتر');

    $this->artisan('qeema:review:rematch', ['--country' => 'XX'])
        ->expectsOutputToContain('qeema:index --country=XX')
        ->assertSuccessful();
});
