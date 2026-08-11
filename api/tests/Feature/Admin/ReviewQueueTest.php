<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ApplyReviewDecision;
use App\Actions\ResolveSubmission;
use App\Exceptions\SubmissionNotObservable;
use App\Filament\Resources\ReviewQueue\Pages\ListReviewQueue;
use App\Filament\Resources\ReviewQueue\ReviewQueueResource;
use App\Models\AnomalyScore;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Submission;
use App\Models\User;
use App\Services\Ml\FakeMlClient;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The review queue
|--------------------------------------------------------------------------
|
| The pipeline refuses to guess. That refusal is only honest if somebody can act
| on what it produces, and until this screen existed nobody could: the actions
| were written and tested, and had no caller — the same condition that made the
| whole pipeline dead until yesterday.
|
| What is protected here is the loop through a human: a decision must produce a
| published observation, teach the matcher, move the reporter's standing, and
| mark the affected snapshot for recomputation. A decision that does only some
| of those is worse than none, because it looks complete.
|
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->reviewer = User::factory()->create();
    $this->actingAs($this->reviewer);

    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->item = CanonicalItem::query()->where('code', 'rice_1kg')->firstOrFail();
});

/**
 * A submission the matcher declined to resolve, with its suggestion recorded.
 */
function awaitingReview(array $attributes = [], float $confidence = 0.6): Submission
{
    $submission = Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'reporter_id' => Reporter::factory()->create([
            'country_id' => test()->country->id,
            'location_id' => test()->location->id,
        ])->id,
        'raw_text' => 'ارز غامض',
        'raw_price' => 9.5,
        'raw_unit' => 'kg',
        'raw_quantity' => 1,
        'currency_code' => 'LYD',
        'observed_at' => now(),
        'status' => Submission::STATUS_PENDING,
        ...$attributes,
    ]);

    (new ResolveSubmission(
        (new FakeMlClient)->willMatch(test()->item->id, 'rice_1kg', $confidence, 'review')
    ))->handle($submission);

    return $submission->fresh();
}

describe('the queue itself', function (): void {
    it('is reachable and lists what is waiting', function (): void {
        $waiting = awaitingReview();

        Livewire::test(ListReviewQueue::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$waiting]);
    });

    it('shows nothing that has already been decided', function (): void {
        // The queue is the set of open decisions. A resolved submission
        // appearing here invites a second, contradictory verdict.
        $decided = Submission::factory()->create([
            'country_id' => $this->country->id,
            'location_id' => $this->location->id,
            'status' => Submission::STATUS_RESOLVED,
        ]);

        Livewire::test(ListReviewQueue::class)
            ->assertCanNotSeeTableRecords([$decided]);
    });

    it('counts the backlog on the navigation item', function (): void {
        awaitingReview();
        awaitingReview();

        // A queue nobody can see the size of is a queue that grows.
        expect(ReviewQueueResource::getNavigationBadge())->toBe('2');
    });

    it('shows no badge when there is nothing to do', function (): void {
        expect(ReviewQueueResource::getNavigationBadge())->toBeNull();
    });

    it('cannot be used to invent a submission', function (): void {
        // Prices come from reporters and importers. One typed in here would
        // reach the index with no provenance behind it.
        expect(ReviewQueueResource::canCreate())->toBeFalse();
    });

    it('is not reachable by a guest', function (): void {
        auth()->logout();

        $this->get(ReviewQueueResource::getUrl('index'))->assertRedirect('/admin/login');
    });
});

describe('approving', function (): void {
    it('publishes the price, teaches the matcher and credits the reporter', function (): void {
        $submission = awaitingReview();
        $reporter = $submission->reporter;
        $alphaBefore = $reporter->reputation_alpha;

        app(ApplyReviewDecision::class)->approve($submission, $this->item, $this->reviewer->id);

        $submission->refresh();

        expect($submission->status)->toBe(Submission::STATUS_RESOLVED)
            ->and(PriceObservation::query()->where('submission_id', $submission->id)->exists())->toBeTrue()
            ->and($submission->resolution->reviewed)->toBeTrue()
            ->and($submission->resolution->reviewed_by_user_id)->toBe($this->reviewer->id);

        // The learning half. Without it the same phrase queues up again
        // tomorrow and the backlog never shrinks.
        expect(CanonicalItemVariant::query()
            ->where('created_from_submission_id', $submission->id)
            ->exists())->toBeTrue();

        expect($reporter->fresh()->reputation_alpha)->toBe($alphaBefore + 1);
    });

    it('marks the affected snapshot for recomputation', function (): void {
        $submission = awaitingReview();

        $snapshot = IndexSnapshot::factory()->create([
            'country_id' => $this->country->id,
            'location_id' => $this->location->id,
            'snapshot_date' => $submission->observed_at->toDateString(),
            'is_stale' => false,
            'stale_marked_at' => null,
        ]);

        app(ApplyReviewDecision::class)->approve($submission, $this->item, $this->reviewer->id);

        // The human branch closes through exactly the same path as the
        // automatic one: observation -> observer -> stale -> scheduled drain.
        expect($snapshot->fresh()->is_stale)->toBeTrue()
            ->and($snapshot->fresh()->stale_marked_at)->not->toBeNull();
    });

    it('does not create a second observation when applied twice', function (): void {
        $submission = awaitingReview();

        app(ApplyReviewDecision::class)->approve($submission, $this->item, $this->reviewer->id);
        app(ApplyReviewDecision::class)->approve($submission->fresh(), $this->item, $this->reviewer->id);

        expect(PriceObservation::query()->where('submission_id', $submission->id)->count())->toBe(1);
    });

    it('refuses to pretend a price it cannot normalise was published', function (): void {
        // A quantity that yields no price per base unit — a corrupt import row,
        // most plausibly. The reviewer is right about the item and there is
        // still nothing defensible to publish.
        //
        // This used to pass quietly: the observation came back null, the
        // submission was marked resolved anyway, and the price never reached
        // the index — with the reviewer having every reason to believe it had.
        //
        // Note that an *unknown unit* does not land here: resolution falls back
        // to the item's default unit rather than failing, which is a separate
        // decision and a defensible one.
        $submission = awaitingReview(['raw_quantity' => 0]);

        expect(fn () => app(ApplyReviewDecision::class)->approve($submission, $this->item, $this->reviewer->id))
            ->toThrow(SubmissionNotObservable::class);

        $submission->refresh();

        expect($submission->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and(PriceObservation::query()->count())->toBe(0)
            // The whole decision rolled back, so the reviewer can reject it or
            // an operator can configure the unit and try again.
            ->and(CanonicalItemVariant::query()->where('created_from_submission_id', $submission->id)->exists())
            ->toBeFalse();
    });

    it('can be driven from the table', function (): void {
        $submission = awaitingReview();

        Livewire::test(ListReviewQueue::class)
            ->callAction(
                TestAction::make('approve')->table($submission),
                ['canonical_item_id' => $this->item->id],
            )
            ->assertHasNoActionErrors();

        expect($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED)
            ->and(PriceObservation::query()->where('submission_id', $submission->id)->exists())->toBeTrue();
    });
});

describe('rejecting', function (): void {
    it('records the reason and counts it against the reporter', function (): void {
        $submission = awaitingReview();
        $betaBefore = $submission->reporter->reputation_beta;

        app(ApplyReviewDecision::class)->reject($submission, 'Photo shows a different product', $this->reviewer->id);

        $submission->refresh();

        expect($submission->status)->toBe(Submission::STATUS_REJECTED)
            ->and($submission->resolution->notes)->toBe('Photo shows a different product')
            ->and($submission->resolution->reviewed_by_user_id)->toBe($this->reviewer->id)
            ->and($submission->reporter->fresh()->reputation_beta)->toBe($betaBefore + 1);
    });

    it('invalidates an observation rather than deleting it', function (): void {
        $submission = awaitingReview();
        app(ApplyReviewDecision::class)->approve($submission, $this->item, $this->reviewer->id);

        app(ApplyReviewDecision::class)->reject($submission->fresh(), 'Wrong after all', $this->reviewer->id);

        // The provenance chain has to survive a correction, so the row stays
        // and stops counting.
        $observation = PriceObservation::query()->where('submission_id', $submission->id)->firstOrFail();

        expect($observation->is_valid)->toBeFalse();
    });

    it('requires a reason when driven from the table', function (): void {
        $submission = awaitingReview();

        Livewire::test(ListReviewQueue::class)
            ->callAction(TestAction::make('reject')->table($submission), ['reason' => ''])
            ->assertHasActionErrors(['reason' => ['required']]);

        // A rejection with no reason is unauditable, and it counts against a
        // real person's standing.
        expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW);
    });
});

describe('working through a backlog', function (): void {
    it('approves every selected suggestion at once', function (): void {
        // The dominant case: the matcher was right and merely unsure. Without
        // bulk approval the queue is not drainable by one person, which is the
        // same as not being drainable.
        $submissions = collect([awaitingReview(), awaitingReview(), awaitingReview()]);

        Livewire::test(ListReviewQueue::class)
            ->selectTableRecords($submissions->all())
            ->callAction(TestAction::make('approve_suggested')->table()->bulk());

        foreach ($submissions as $submission) {
            expect($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED);
        }

        expect(PriceObservation::query()->count())->toBe(3);
    });

    it('leaves a submission the matcher had no opinion about', function (): void {
        // Nothing to approve *to*. Silently skipping is fine; silently
        // reporting success would not be.
        $unsuggested = awaitingReview();
        $unsuggested->resolution->forceFill(['canonical_item_id' => null])->save();

        Livewire::test(ListReviewQueue::class)
            ->selectTableRecords([$unsuggested])
            ->callAction(TestAction::make('approve_suggested')->table()->bulk());

        expect($unsuggested->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and(PriceObservation::query()->count())->toBe(0);
    });
});

describe('filtering', function (): void {
    it('separates a flagged price from an unsure match', function (): void {
        $unsure = awaitingReview();
        $flagged = awaitingReview();

        AnomalyScore::factory()->create([
            'submission_id' => $flagged->id,
            'verdict' => AnomalyScore::VERDICT_SUSPECT,
        ]);

        // Different kinds of work: one is "which item is this", the other is
        // "is this price real". A reviewer usually wants one kind at a time.
        Livewire::test(ListReviewQueue::class)
            ->filterTable('why', 'flagged')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$unsure]);
    });

    it('finds the submissions nothing could be suggested for', function (): void {
        $unsure = awaitingReview();
        $blind = awaitingReview();
        $blind->resolution->forceFill(['canonical_item_id' => null])->save();

        Livewire::test(ListReviewQueue::class)
            ->filterTable('why', 'no_suggestion')
            ->assertCanSeeTableRecords([$blind])
            ->assertCanNotSeeTableRecords([$unsure]);
    });
});

describe('ordering', function (): void {
    it('puts the oldest first, so the tail is not abandoned', function (): void {
        $older = awaitingReview(['observed_at' => now()->subDays(3)]);
        $newer = awaitingReview(['observed_at' => now()]);

        Livewire::test(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$older, $newer], inOrder: true);
    });

    it('can be sorted by what a decision actually moves', function (): void {
        // Basket weight of the suggested item: an hour spent on heavy items
        // corrects more of the published figure than an hour spent on light
        // ones.
        $light = awaitingReview();
        $heavy = awaitingReview();

        $heavyItem = CanonicalItem::query()->where('code', 'infant_formula_400g')->firstOrFail();
        $heavy->resolution->forceFill(['canonical_item_id' => $heavyItem->id])->save();

        Livewire::test(ListReviewQueue::class)
            ->sortTable('review_weight', 'desc')
            ->assertCanSeeTableRecords([$heavy, $light], inOrder: true);
    });
});
