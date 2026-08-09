<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Actions\ApplyReviewDecision;
use App\Actions\ResolveSubmission;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Resolution;
use App\Models\Submission;
use App\Models\Unit;
use App\Services\Ml\FakeMlClient;
use App\Services\Ml\MatchResult;
use App\Services\Ml\MlClient;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonSchema\Validator;

/*
|--------------------------------------------------------------------------
| The Laravel <-> ML boundary
|--------------------------------------------------------------------------
|
| Two things are being protected here.
|
| The contract tests keep the test double honest: the fake is validated against
| the same schema the real Python service is validated against, so a fake that
| drifts from the service breaks a build rather than production.
|
| The resolution tests protect the platform's central promise — that a price
| observation means somebody reported this price for this item. The failure mode
| guarded against is not being wrong; it is being wrong *silently*.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    $this->item = CanonicalItem::query()->where('code', 'rice_1kg')->firstOrFail();

    MlClient::reset();
});

function contractValidator(): Validator
{
    return new Validator;
}

function assertMatchesContract(array $payload): void
{
    $schemaPath = base_path('../contracts/ml-match-response.json');
    expect(file_exists($schemaPath))->toBeTrue("Shared contract missing at {$schemaPath}");

    $validator = contractValidator();
    $data = json_decode((string) json_encode($payload));

    $validator->validate($data, (object) ['$ref' => 'file://'.realpath($schemaPath)]);

    $errors = array_map(
        fn (array $e): string => sprintf('%s: %s', $e['property'] ?: '<root>', $e['message']),
        $validator->getErrors(),
    );

    expect($errors)->toBe([], "Contract violations:\n".implode("\n", $errors));
}

function submissionFor(string $rawText, string $unit = 'kg', float $price = 6.5): Submission
{
    return Submission::factory()->create([
        'country_id' => test()->country->id,
        'location_id' => test()->location->id,
        'raw_text' => $rawText,
        'raw_price' => $price,
        'raw_unit' => $unit,
        'raw_quantity' => 1,
        'currency_code' => 'LYD',
        'status' => Submission::STATUS_PENDING,
    ]);
}

describe('the fake honours the shared contract', function () {
    it('produces a contract-valid auto-resolve response', function () {
        $fake = (new FakeMlClient)->willMatch(1, 'rice_1kg', 0.95);
        $result = $fake->match(test()->country, 'ارز');

        assertMatchesContract([
            'normalised_text' => $result->normalisedText,
            'action' => $result->action,
            'reason' => $result->reason,
            'candidates' => $result->candidates,
            'model_version' => $result->modelVersion,
            'calibrated' => $result->calibrated,
        ]);
    });

    it('produces a contract-valid review response', function () {
        $fake = (new FakeMlClient)->willMatch(1, 'rice_1kg', 0.6, MatchResult::ACTION_REVIEW);
        $result = $fake->match(test()->country, 'ارز');

        assertMatchesContract([
            'normalised_text' => $result->normalisedText,
            'action' => $result->action,
            'reason' => $result->reason,
            'candidates' => $result->candidates,
            'model_version' => $result->modelVersion,
            'calibrated' => $result->calibrated,
        ]);
    });

    it('produces a contract-valid empty-candidate response', function () {
        // The shape a test double is most likely to model wrongly.
        $result = MatchResult::fromArray([
            'normalised_text' => 'nonsense',
            'action' => 'reject',
            'reason' => 'No candidate items found.',
            'candidates' => [],
            'model_version' => 'fake-0.1.0',
            'calibrated' => false,
        ]);

        assertMatchesContract([
            'normalised_text' => $result->normalisedText,
            'action' => $result->action,
            'reason' => $result->reason,
            'candidates' => $result->candidates,
            'model_version' => $result->modelVersion,
            'calibrated' => $result->calibrated,
        ]);
    });
});

describe('parsing a response', function () {
    it('rejects a response missing a required field', function () {
        expect(fn () => MatchResult::fromArray(['action' => 'auto_resolve']))
            ->toThrow(InvalidArgumentException::class, 'missing');
    });

    it('degrades an unrecognised action to review rather than auto-resolving', function () {
        // A service that starts returning a new action value must never be read
        // as permission to skip a human.
        $result = MatchResult::fromArray([
            'normalised_text' => 'x',
            'action' => 'something_new',
            'reason' => 'r',
            'candidates' => [],
            'model_version' => 'v',
            'calibrated' => false,
        ]);

        expect($result->action)->toBe(MatchResult::ACTION_REVIEW)
            ->and($result->shouldAutoResolve())->toBeFalse();
    });

    it('never auto-resolves without a candidate', function () {
        $result = MatchResult::fromArray([
            'normalised_text' => 'x',
            'action' => 'auto_resolve',
            'reason' => 'r',
            'candidates' => [],
            'model_version' => 'v',
            'calibrated' => false,
        ]);

        expect($result->shouldAutoResolve())->toBeFalse();
    });
});

describe('resolving a submission', function () {
    it('creates a price observation when the matcher is confident', function () {
        $submission = submissionFor('ارز ابيض');
        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95);

        $resolution = (new ResolveSubmission($fake))->handle($submission);

        expect($resolution->canonical_item_id)->toBe($this->item->id)
            ->and($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED)
            ->and(PriceObservation::query()->count())->toBe(1);
    });

    it('normalises the price to a per-base-unit figure', function () {
        // 12.50 for 500 g is 25.00 per kg. Getting this wrong moves a published
        // figure by three orders of magnitude.
        $submission = submissionFor('ارز', unit: 'g', price: 12.50);
        $submission->forceFill(['raw_quantity' => 500])->save();

        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95);
        (new ResolveSubmission($fake))->handle($submission);

        expect((float) PriceObservation::query()->firstOrFail()->normalized_price_per_base_unit)
            ->toBe(25.0);
    });

    it('queues for review rather than guessing when confidence is low', function () {
        $submission = submissionFor('something ambiguous');
        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.6, MatchResult::ACTION_REVIEW);

        (new ResolveSubmission($fake))->handle($submission);

        expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and(PriceObservation::query()->count())->toBe(0);
    });

    it('queues for review when the ML service is unreachable', function () {
        // The critical degradation path: a container restart must not discard a
        // valid observation.
        $submission = submissionFor('ارز');
        $fake = (new FakeMlClient)->pretendUnavailable();

        $resolution = (new ResolveSubmission($fake))->handle($submission);

        expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and($resolution->notes)->toContain('unavailable')
            ->and(PriceObservation::query()->count())->toBe(0);
    });

    it('queues for review when the matched item is not in this catalogue', function () {
        $submission = submissionFor('ارز');
        $fake = (new FakeMlClient)->willMatch(999999, 'ghost_item', 0.99);

        $resolution = (new ResolveSubmission($fake))->handle($submission);

        expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and($resolution->notes)->toContain('not in this country catalogue')
            ->and(PriceObservation::query()->count())->toBe(0);
    });

    it('queues for review rather than assuming a unit it does not know', function () {
        // Guessing a unit is how a price per gram becomes a price per kilo.
        $submission = submissionFor('ارز', unit: 'furlong');
        Unit::query()->where('country_id', $this->country->id)->where('code', 'kg')->delete();
        $this->item->forceFill(['default_unit_code' => 'furlong'])->save();

        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.99);
        (new ResolveSubmission($fake))->handle($submission);

        expect($submission->fresh()->status)->toBe(Submission::STATUS_NEEDS_REVIEW)
            ->and(PriceObservation::query()->count())->toBe(0);
    });

    it('keeps the full candidate list for later diagnosis', function () {
        // Whether the right answer ranked second or was never retrieved are
        // different failures needing different fixes.
        $submission = submissionFor('ارز');
        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95, candidates: [
            ['canonical_item_id' => $this->item->id, 'canonical_item_code' => 'rice_1kg', 'lexical_score' => 0.9, 'semantic_score' => 0.0, 'fused_score' => 0.9, 'confidence' => 0.95, 'matched_variant' => 'ارز'],
            ['canonical_item_id' => 2, 'canonical_item_code' => 'other', 'lexical_score' => 0.5, 'semantic_score' => 0.0, 'fused_score' => 0.5, 'confidence' => 0.6, 'matched_variant' => null],
        ]);

        $resolution = (new ResolveSubmission($fake))->handle($submission);

        expect($resolution->candidates)->toHaveCount(2)
            ->and($resolution->rankOfCorrectAnswer($this->item->id))->toBe(1);
    });

    it('freezes reporter reputation at ingestion time', function () {
        // Recomputing an old snapshot must not drift with a reporter's score.
        $submission = submissionFor('ارز');
        $submission->reporter->forceFill(['reputation' => 0.8])->save();

        $fake = (new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95);
        (new ResolveSubmission($fake))->handle($submission->fresh());

        expect((float) PriceObservation::query()->firstOrFail()->reputation_at_time)->toBe(0.8);
    });
});

describe('the review feedback loop', function () {
    it('teaches the matcher the phrase that defeated it', function () {
        // A queue that only fixes the submission in front of the reviewer never
        // shrinks; the same phrase arrives again tomorrow.
        $submission = submissionFor('حليب اطفل نيدو');
        (new ResolveSubmission((new FakeMlClient)->pretendUnavailable()))->handle($submission);

        app(ApplyReviewDecision::class)->approve($submission->fresh(), $this->item);

        $variant = CanonicalItemVariant::query()
            ->where('canonical_item_id', $this->item->id)
            ->where('source', CanonicalItemVariant::SOURCE_HUMAN_REVIEW)
            ->first();

        expect($variant)->not->toBeNull()
            ->and($variant->text)->toBe('حليب اطفل نيدو')
            ->and($variant->created_from_submission_id)->toBe($submission->id);
    });

    it('stores the learned variant in normalised form', function () {
        $submission = submissionFor('حليب أطفال ٤٠٠ غرام جديد');
        (new ResolveSubmission((new FakeMlClient)->pretendUnavailable()))->handle($submission);

        app(ApplyReviewDecision::class)->approve($submission->fresh(), $this->item);

        $variant = CanonicalItemVariant::query()
            ->where('source', CanonicalItemVariant::SOURCE_HUMAN_REVIEW)
            ->firstOrFail();

        expect($variant->normalized_text)->toBe('حليب اطفال 400 غرام جديد');
    });

    it('creates the observation only once a human has approved it', function () {
        $submission = submissionFor('ارز غامض');
        (new ResolveSubmission((new FakeMlClient)->pretendUnavailable()))->handle($submission);

        expect(PriceObservation::query()->count())->toBe(0);

        app(ApplyReviewDecision::class)->approve($submission->fresh(), $this->item);

        expect(PriceObservation::query()->count())->toBe(1)
            ->and($submission->fresh()->status)->toBe(Submission::STATUS_RESOLVED);
    });

    it('records the human decision as certain and reviewed', function () {
        $submission = submissionFor('ارز');
        (new ResolveSubmission((new FakeMlClient)->pretendUnavailable()))->handle($submission);

        $resolution = app(ApplyReviewDecision::class)->approve($submission->fresh(), $this->item);

        expect($resolution->method)->toBe(Resolution::METHOD_HUMAN)
            ->and((float) $resolution->confidence)->toBe(1.0)
            ->and($resolution->reviewed)->toBeTrue();
    });

    it('updates reporter reputation only on a human verdict', function () {
        $submission = submissionFor('ارز');
        $before = $submission->reporter->reputation_alpha;

        app(ApplyReviewDecision::class)->approve($submission, $this->item);

        expect($submission->reporter->fresh()->reputation_alpha)->toBe($before + 1);
    });

    it('invalidates rather than deletes an observation on rejection', function () {
        // The provenance chain has to survive a correction.
        $submission = submissionFor('ارز');
        (new ResolveSubmission((new FakeMlClient)->willMatch($this->item->id, 'rice_1kg', 0.95)))
            ->handle($submission);

        app(ApplyReviewDecision::class)->reject($submission->fresh(), 'Illegible photo');

        $observation = PriceObservation::query()->firstOrFail();

        expect($observation->is_valid)->toBeFalse()
            ->and(PriceObservation::query()->count())->toBe(1)
            ->and($submission->fresh()->status)->toBe(Submission::STATUS_REJECTED);
    });

    it('counts a rejection against the reporter', function () {
        $submission = submissionFor('ارز');
        $before = $submission->reporter->reputation_beta;

        app(ApplyReviewDecision::class)->reject($submission, 'Nonsense');

        expect($submission->reporter->fresh()->reputation_beta)->toBe($before + 1);
    });

    it('does not reassign a variant already claimed by another item', function () {
        // A phrase mapping to two items is a genuine catalogue ambiguity a
        // reviewer should see, not something to silently overwrite.
        $other = CanonicalItem::query()->where('code', 'wheat_flour_1kg')->firstOrFail();
        $submission = submissionFor('ارز');

        app(ApplyReviewDecision::class)->approve($submission, $other);

        $variants = CanonicalItemVariant::query()->where('normalized_text', 'ارز')->get();

        expect($variants)->toHaveCount(1)
            ->and($variants->first()->canonical_item_id)->not->toBe($other->id);
    });
});

describe('the circuit breaker', function () {
    afterEach(fn () => MlClient::reset());

    it('reports available when the circuit is closed', function () {
        expect((new MlClient)->isAvailable())->toBeTrue();
    });

    it('opens after repeated failures and stops calling the service', function () {
        // A service that is down must stop being asked, rather than adding its
        // timeout to every submission for the duration of the outage.
        Http::fake(['*' => Http::response('boom', 500)]);

        $client = new MlClient;
        $threshold = (int) config('qeema.ml.circuit_breaker.failure_threshold');

        for ($i = 0; $i < $threshold; $i++) {
            $client->match($this->country, 'ارز');
        }

        expect($client->isAvailable())->toBeFalse();

        $callsBefore = count(Http::recorded());
        $client->match($this->country, 'ارز');

        expect(count(Http::recorded()))->toBe($callsBefore);
    });

    it('returns null rather than throwing when the service errors', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        expect((new MlClient)->match($this->country, 'ارز'))->toBeNull();
    });

    it('returns null rather than throwing on a malformed response', function () {
        // A service returning garbage must trip the breaker like any other
        // failure, not slip past it.
        Http::fake(['*' => Http::response(['unexpected' => true], 200)]);

        expect((new MlClient)->match($this->country, 'ارز'))->toBeNull();
    });

    it('resets the failure count after a success', function () {
        // A sequence rather than two Http::fake() calls: fake() *merges* stubs
        // and the first match wins, so a second fake never takes effect. The
        // retry setting means the first logical call consumes several
        // responses, hence the repeated failures before the success.
        Http::fakeSequence()
            ->push('boom', 500)
            ->push('boom', 500)
            ->push('boom', 500)
            ->push([
                'normalised_text' => 'ارز',
                'action' => 'auto_resolve',
                'reason' => 'ok',
                'candidates' => [],
                'model_version' => 'v',
                'calibrated' => false,
            ], 200);

        $client = new MlClient;
        $client->match($this->country, 'ارز');

        expect(Cache::get('qeema:ml:failures'))->toBe(1);

        $client->match($this->country, 'ارز');

        expect(Cache::get('qeema:ml:failures'))->toBeNull();
    });
});
