<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ApplyReviewDecision;
use App\Actions\ResolveSubmission;
use App\Jobs\RejectReviewBacklogJob;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use App\Models\Unit;
use App\Models\UnmatchablePhrase;
use App\Services\Ml\MatchResult;
use App\Services\Ml\MlClientInterface;
use App\Support\Text\TextNormalizer;

/**
 * The other half of the review loop.
 *
 * Approving teaches the matcher what a phrase means. Until now rejecting taught
 * it nothing, so on the scale dataset five pieces of junk — `١٢٣٤`, `test 123`,
 * `تجربه`, `السلام عليكم`, `asdasdasd` — were waiting for 5,138 separate human
 * decisions.
 *
 * Most of these tests are about the way this feature could do damage rather
 * than the way it helps, because the damaging version is easy to write by
 * accident: infer "the phrase is meaningless" from any rejection, and the first
 * reviewer who rejects a rice report over a silly price deletes rice.
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

it('remembers a phrase only when the reviewer says it is not a product', function (): void {
    $submission = ($this->queue)('السلام عليكم');

    app(ApplyReviewDecision::class)->reject($submission, 'a greeting', null, phraseIsNotAProduct: true);

    expect(UnmatchablePhrase::query()->forCountry($this->country->id)->count())->toBe(1);
});

it('remembers nothing when the reviewer rejects for any other reason', function (): void {
    // The destructive case this design exists to prevent: a rice report with an
    // absurd price is rejected, and rice must go on resolving for everyone.
    $submission = ($this->queue)('أرز');

    app(ApplyReviewDecision::class)->reject($submission, 'price is obviously wrong');

    expect(UnmatchablePhrase::query()->count())->toBe(0);
});

it('refuses to rule against a phrase the catalogue calls a product', function (): void {
    // Reviewer and catalogue disagree. Recording the ruling would silently
    // start discarding a catalogued product; a person should settle it.
    CanonicalItemVariant::query()->create([
        'canonical_item_id' => $this->item->id,
        'text' => 'أرز',
        'normalized_text' => app(TextNormalizer::class)->normalize('أرز'),
        'source' => CanonicalItemVariant::SOURCE_SEED,
    ]);

    app(ApplyReviewDecision::class)->reject(
        ($this->queue)('أرز'), 'not a product', null, phraseIsNotAProduct: true,
    );

    expect(UnmatchablePhrase::query()->count())->toBe(0);
});

it('rejects every identical row already queued', function (): void {
    $ruled = ($this->queue)('asdasdasd');
    $siblings = collect(range(1, 4))->map(fn (): Submission => ($this->queue)('asdasdasd'));

    app(ApplyReviewDecision::class)->reject($ruled, 'keyboard mash', null, phraseIsNotAProduct: true);
    (new RejectReviewBacklogJob($this->country->id, 'asdasdasd', (string) $ruled->id, 'keyboard mash'))->handle();

    foreach ($siblings as $sibling) {
        expect($sibling->refresh()->status)->toBe(Submission::STATUS_REJECTED)
            ->and($sibling->resolution->method)->toBe(Resolution::METHOD_RULE)
            ->and($sibling->resolution->canonical_item_id)->toBeNull();
    }
});

it('does not dock reputations for reporters nobody looked at', function (): void {
    // One click must not become a verdict on hundreds of people. Only the
    // submission the reviewer actually saw counts against its reporter.
    $ruled = ($this->queue)('asdasdasd');
    ($this->queue)('asdasdasd');

    app(ApplyReviewDecision::class)->reject($ruled, 'mash', null, phraseIsNotAProduct: true);
    $after = $this->reporter->refresh()->submissions_rejected;

    (new RejectReviewBacklogJob($this->country->id, 'asdasdasd', (string) $ruled->id, 'mash'))->handle();

    expect($this->reporter->refresh()->submissions_rejected)->toBe($after);
});

it('rejects a later submission carrying the phrase without asking a human', function (): void {
    UnmatchablePhrase::query()->create([
        'country_id' => $this->country->id,
        'text' => 'صباح الخير',
        'normalized_text' => app(TextNormalizer::class)->normalize('صباح الخير'),
        'reason' => 'a greeting',
    ]);

    // The matcher must never be consulted; the decision is already made.
    $ml = Mockery::mock(MlClientInterface::class);
    $ml->shouldNotReceive('match');

    $resolution = (new ResolveSubmission($ml))->handle(($this->queue)('صباح الخير', Submission::STATUS_PENDING));

    expect($resolution->method)->toBe(Resolution::METHOD_RULE)
        ->and($resolution->canonical_item_id)->toBeNull()
        ->and($resolution->notes)->toContain('a greeting');
});

it('counts how often a ruling earns its keep', function (): void {
    $phrase = UnmatchablePhrase::query()->create([
        'country_id' => $this->country->id,
        'text' => 'تجربه',
        'normalized_text' => app(TextNormalizer::class)->normalize('تجربه'),
    ]);

    $ml = Mockery::mock(MlClientInterface::class);
    $ml->shouldNotReceive('match');
    $resolver = new ResolveSubmission($ml);

    $resolver->handle(($this->queue)('تجربه', Submission::STATUS_PENDING));
    $resolver->handle(($this->queue)('تجربه', Submission::STATUS_PENDING));

    expect($phrase->refresh()->times_matched)->toBe(2);
});

it('does not apply one country\'s ruling to another', function (): void {
    $elsewhere = Country::factory()->create(['code' => 'YY', 'currency_code' => 'YYD']);

    UnmatchablePhrase::query()->create([
        'country_id' => $elsewhere->id,
        'text' => 'أرز',
        'normalized_text' => app(TextNormalizer::class)->normalize('أرز'),
    ]);

    // A phrase that is noise in one deployment may be a product in another, so
    // the matcher must still be asked here.
    $ml = Mockery::mock(MlClientInterface::class);
    $ml->shouldReceive('match')->once()->andReturn(new MatchResult('أرز', MatchResult::ACTION_REVIEW, 'unsure', [], 'test', false));

    (new ResolveSubmission($ml))->handle(($this->queue)('أرز', Submission::STATUS_PENDING));
});

it('keeps the discarded price traceable rather than deleting it', function (): void {
    UnmatchablePhrase::query()->create([
        'country_id' => $this->country->id,
        'text' => 'test 123',
        'normalized_text' => app(TextNormalizer::class)->normalize('test 123'),
        'reason' => 'a test message',
    ]);

    $ml = Mockery::mock(MlClientInterface::class);
    $ml->shouldNotReceive('match');
    $submission = ($this->queue)('test 123', Submission::STATUS_PENDING);

    (new ResolveSubmission($ml))->handle($submission);

    // Rejected, not deleted, and the reason travels with it — a price thrown
    // away by an automatic rule has to be recoverable if the rule was wrong.
    expect($submission->refresh()->status)->toBe(Submission::STATUS_REJECTED)
        ->and($submission->resolution->notes)->toContain('a test message');
});
