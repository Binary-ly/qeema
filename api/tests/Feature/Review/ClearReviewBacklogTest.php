<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ApplyReviewDecision;
use App\Actions\ResolveSubmission;
use App\Jobs\ClearReviewBacklogJob;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use App\Models\Unit;
use Illuminate\Support\Facades\Queue;

/**
 * One reviewer decision has to clear every identical row already queued.
 *
 * Measured on the 3.2 million-row dataset: 367,392 submissions awaiting review
 * carried 31,044 distinct texts. Without this the commonest phrase would have
 * been shown to a reviewer 1,415 separate times.
 */
beforeEach(function (): void {
    $this->country = Country::factory()->create(['code' => 'XX', 'currency_code' => 'XXD']);

    Unit::query()->create([
        'country_id' => $this->country->id, 'code' => 'kg', 'name' => 'Kilogram',
        'dimension' => 'mass', 'base_unit_code' => 'kg', 'factor_to_base' => 1,
    ]);

    $this->item = CanonicalItem::query()->create([
        'country_id' => $this->country->id, 'code' => 'rice_1kg', 'name_en' => 'Rice',
        'category' => 'staples', 'default_unit_code' => 'kg', 'default_quantity' => 1,
        'is_active' => true,
    ]);

    $this->location = Location::factory()->create(['country_id' => $this->country->id]);
    $this->reporter = Reporter::factory()->create(['country_id' => $this->country->id]);
    $this->source = Source::factory()->create(['country_id' => $this->country->id]);

    $this->queue = function (string $text, string $status = Submission::STATUS_NEEDS_REVIEW): Submission {
        $submission = new Submission;

        $submission->forceFill([
            'country_id' => $this->country->id,
            'location_id' => $this->location->id,
            'reporter_id' => $this->reporter->id,
            'source_id' => $this->source->id,
            'raw_text' => $text,
            'raw_price' => 10.0,
            'raw_unit' => 'kg',
            'currency_code' => 'XXD',
            'observed_at' => now(),
            'collected_at' => now(),
            'ingested_at' => now(),
            'status' => $status,
        ])->save();

        return $submission->refresh();
    };
});

it('resolves every queued submission carrying the same text', function (): void {
    $decided = ($this->queue)('رز');
    $siblings = collect(range(1, 5))->map(fn (): Submission => ($this->queue)('رز'));

    app(ApplyReviewDecision::class)->approve($decided, $this->item);
    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    foreach ($siblings as $sibling) {
        expect($sibling->refresh()->status)->toBe(Submission::STATUS_RESOLVED)
            ->and($sibling->resolution->canonical_item_id)->toBe($this->item->id);
    }
});

it('records the resolution as an exact match, not as a human one', function (): void {
    // A reviewer never saw these rows. Marking them `human` would say somebody
    // approved a price nobody looked at; `fused` would claim a model ran.
    $decided = ($this->queue)('رز');
    $sibling = ($this->queue)('رز');

    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    $resolution = $sibling->refresh()->resolution;

    expect($resolution->method)->toBe(Resolution::METHOD_EXACT)
        ->and($resolution->reviewed)->toBeFalse()
        ->and($resolution->notes)->toContain((string) $decided->id);
});

it('does not credit reporters for a decision nobody made about them', function (): void {
    // The reviewer confirmed what the phrase means, not that a thousand
    // separate prices are honest.
    $decided = ($this->queue)('رز');
    ($this->queue)('رز');

    $before = $this->reporter->refresh()->submissions_accepted;

    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    expect($this->reporter->refresh()->submissions_accepted)->toBe($before);
});

it('leaves submissions carrying different text alone', function (): void {
    $decided = ($this->queue)('رز');
    $other = ($this->queue)('زيت');

    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    expect($other->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('leaves submissions that are not awaiting review alone', function (): void {
    $decided = ($this->queue)('رز');
    $pending = ($this->queue)('رز', Submission::STATUS_PENDING);

    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    expect($pending->refresh()->status)->toBe(Submission::STATUS_PENDING);
});

it('runs twice without changing anything the second time', function (): void {
    $decided = ($this->queue)('رز');
    $sibling = ($this->queue)('رز');
    $job = new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id);

    $job->handle(app(ResolveSubmission::class));
    $first = $sibling->refresh()->resolution->updated_at;

    $job->handle(app(ResolveSubmission::class));

    expect($sibling->refresh()->resolution->updated_at->equalTo($first))->toBeTrue();
});

it('does not touch another country', function (): void {
    $elsewhere = Country::factory()->create(['code' => 'YY', 'currency_code' => 'YYD']);
    $decided = ($this->queue)('رز');
    $foreign = ($this->queue)('رز');
    $foreign->forceFill(['country_id' => $elsewhere->id])->save();

    (new ClearReviewBacklogJob($this->country->id, 'رز', $this->item->id, (string) $decided->id))
        ->handle(app(ResolveSubmission::class));

    expect($foreign->refresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
});

it('is dispatched when a review teaches the matcher a new phrase', function (): void {
    Queue::fake();

    $decided = ($this->queue)('رز مصري');
    app(ApplyReviewDecision::class)->approve($decided, $this->item);

    Queue::assertPushed(ClearReviewBacklogJob::class);
});

it('is not dispatched when the phrase was already known', function (): void {
    // Nothing was learned, so there is no backlog this decision could clear.
    $first = ($this->queue)('رز');
    app(ApplyReviewDecision::class)->approve($first, $this->item);

    Queue::fake();

    $second = ($this->queue)('رز');
    app(ApplyReviewDecision::class)->approve($second, $this->item);

    Queue::assertNotPushed(ClearReviewBacklogJob::class);
});
