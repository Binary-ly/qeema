<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Location;
use App\Models\PriceObservation;
use App\Models\Resolution;
use App\Models\Source;
use App\Models\Submission;
use Illuminate\Database\UniqueConstraintViolationException;

/*
|--------------------------------------------------------------------------
| Country configuration and the provenance chain
|--------------------------------------------------------------------------
*/

describe('country configuration', function () {
    it('supplies estimator defaults when a country omits them', function () {
        // A country file missing a key must still compute, rather than failing
        // deep inside the estimator with a null.
        $country = Country::factory()->create(['index_config' => ['observation_window_days' => 14]]);

        $settings = $country->indexSettings();

        expect($settings['observation_window_days'])->toBe(14)
            ->and($settings['recency_half_life_days'])->toBe(3)
            ->and($settings['bootstrap_draws'])->toBe(500);
    });

    it('falls back to every default when index config is absent', function () {
        $country = Country::factory()->create(['index_config' => null]);

        expect($country->indexSettings())->toBe(Country::INDEX_DEFAULTS);
    });

    it('defaults to the parallel rate, which is what people can transact at', function () {
        $country = Country::factory()->create();

        expect($country->fxRateType())->toBe('parallel');
    });

    it('honours a country configured to use the official rate', function () {
        $country = Country::factory()->usingOfficialRate()->create();

        expect($country->fxRateType())->toBe('official');
    });

    it('falls back to manual FX entry when no provider is configured', function () {
        $country = Country::factory()->create(['fx_config' => null]);

        expect($country->fxProvider())->toBe('manual');
    });

    it('carries locales as configuration rather than assuming English', function () {
        $country = Country::factory()->rightToLeft()->create();

        expect($country->default_locale)->toBe('ar')
            ->and($country->locales)->toContain('ar')
            ->and($country->currency_minor_units)->toBe(3);
    });

    it('resolves the basket version in force on a historical date', function () {
        // Recomputing an old snapshot must use the basket that was actually in
        // force then, not today's definition.
        $country = Country::factory()->create();
        $v1 = Basket::factory()->version(1)->create([
            'country_id' => $country->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-05-31',
        ]);
        $v2 = Basket::factory()->version(2)->create([
            'country_id' => $country->id,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
        ]);

        expect($country->basketOn(new DateTimeImmutable('2026-03-01'))?->id)->toBe($v1->id)
            ->and($country->basketOn(new DateTimeImmutable('2026-08-01'))?->id)->toBe($v2->id);
    });

    it('returns no basket for a date before any version existed', function () {
        $country = Country::factory()->create();
        Basket::factory()->create(['country_id' => $country->id, 'effective_from' => '2026-01-01']);

        expect($country->basketOn(new DateTimeImmutable('2025-01-01')))->toBeNull();
    });
});

describe('spatial neighbours without a commercial geocoder', function () {
    it('computes a known great-circle distance', function () {
        // Two points one degree of latitude apart are ~111 km.
        $a = Location::factory()->at(32.0, 13.0)->make();
        $b = Location::factory()->at(33.0, 13.0)->make();

        expect($a->distanceKmTo($b))->toEqualWithDelta(111.19, 0.5);
    });

    it('is symmetric', function () {
        $a = Location::factory()->at(32.0, 13.0)->make();
        $b = Location::factory()->at(31.0, 20.0)->make();

        expect($a->distanceKmTo($b))->toEqualWithDelta($b->distanceKmTo($a), 1e-9);
    });

    it('reports zero distance to itself', function () {
        $a = Location::factory()->at(32.0, 13.0)->make();

        expect($a->distanceKmTo($a))->toEqualWithDelta(0.0, 1e-9);
    });

    it('cannot compute a distance without coordinates', function () {
        $a = Location::factory()->withoutCoordinates()->make();
        $b = Location::factory()->at(32.0, 13.0)->make();

        expect($a->distanceKmTo($b))->toBeNull();
    });

    it('orders neighbours by true distance', function () {
        $country = Country::factory()->create();
        $origin = Location::factory()->at(32.0, 13.0)->create(['country_id' => $country->id]);
        $near = Location::factory()->at(32.1, 13.1)->create(['country_id' => $country->id]);
        $mid = Location::factory()->at(33.0, 14.0)->create(['country_id' => $country->id]);
        $far = Location::factory()->at(40.0, 25.0)->create(['country_id' => $country->id]);

        $neighbours = $origin->nearestNeighbours(3);

        expect($neighbours->pluck('id')->all())->toBe([$near->id, $mid->id, $far->id]);
    });

    it('excludes locations in other countries', function () {
        $country = Country::factory()->create();
        $origin = Location::factory()->at(32.0, 13.0)->create(['country_id' => $country->id]);
        Location::factory()->at(32.01, 13.01)->create(['country_id' => Country::factory()->create()->id]);

        expect($origin->nearestNeighbours(3))->toHaveCount(0);
    });

    it('excludes inactive locations', function () {
        $country = Country::factory()->create();
        $origin = Location::factory()->at(32.0, 13.0)->create(['country_id' => $country->id]);
        Location::factory()->at(32.01, 13.01)->inactive()->create(['country_id' => $country->id]);

        expect($origin->nearestNeighbours(3))->toHaveCount(0);
    });

    it('returns nothing when the origin has no coordinates', function () {
        $country = Country::factory()->create();
        $origin = Location::factory()->withoutCoordinates()->create(['country_id' => $country->id]);
        Location::factory()->at(32.0, 13.0)->create(['country_id' => $country->id]);

        expect($origin->nearestNeighbours())->toHaveCount(0);
    });
});

describe('provenance chain', function () {
    it('links a published observation back to its raw submission text', function () {
        $submission = Submission::factory()->withArabicText()->create();
        $observation = PriceObservation::factory()->create(['submission_id' => $submission->id]);

        expect($observation->submission->raw_text)->toBe($submission->raw_text);
    });

    it('supersedes a corrected observation instead of overwriting it', function () {
        // The original must survive, or an audit of a historical figure becomes
        // impossible and the correction is untraceable.
        $original = PriceObservation::factory()->pricedAt(25.0, '2026-03-01')->create();
        $correction = PriceObservation::factory()->pricedAt(2.5, '2026-03-01')->create();

        $original->supersedeWith($correction);

        expect($original->fresh()->is_valid)->toBeFalse()
            ->and($original->fresh()->superseded_by_id)->toBe($correction->id)
            ->and($original->fresh()->normalized_price_per_base_unit)->toBe(25.0);
    });

    it('excludes superseded observations from the valid scope', function () {
        $original = PriceObservation::factory()->create();
        $correction = PriceObservation::factory()->create();
        $original->supersedeWith($correction);

        expect(PriceObservation::query()->valid()->pluck('id')->all())->toBe([$correction->id]);
    });

    it('preserves the observation date when a submission syncs days later', function () {
        // A week of backlog syncing at once must not land on today, or it would
        // distort both today's index and the days it actually belongs to.
        $submission = Submission::factory()->syncedLate(5)->create();

        expect($submission->wasSubmittedOffline())->toBeTrue()
            ->and($submission->syncLagSeconds())->toBeGreaterThan(4 * 86400);
    });

    it('reports no sync lag for an online submission', function () {
        $submission = Submission::factory()->create();

        expect($submission->wasSubmittedOffline())->toBeFalse()
            ->and($submission->syncLagSeconds())->toBe(0);
    });

    it('refuses a duplicate idempotency key from the same reporter', function () {
        // This database constraint is what makes offline replay safe: a retried
        // submission collides here rather than being counted twice.
        $first = Submission::factory()->create();

        expect(fn () => Submission::factory()->create([
            'reporter_id' => $first->reporter_id,
            'client_idempotency_key' => $first->client_idempotency_key,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('matcher feedback loop', function () {
    it('records where the correct answer ranked among candidates', function () {
        $resolution = Resolution::factory()->withCandidates([
            ['canonical_item_id' => 11, 'score' => 0.9],
            ['canonical_item_id' => 22, 'score' => 0.8],
            ['canonical_item_id' => 33, 'score' => 0.7],
        ])->create();

        expect($resolution->rankOfCorrectAnswer(22))->toBe(2);
    });

    it('distinguishes a ranking failure from a retrieval failure', function () {
        // Not retrieved at all is a different problem from ranked second, and
        // needs a different fix, so the two must not look the same.
        $resolution = Resolution::factory()->withCandidates([
            ['canonical_item_id' => 11, 'score' => 0.9],
        ])->create();

        expect($resolution->rankOfCorrectAnswer(999))->toBeNull();
    });

    it('treats a high-confidence machine match as auto-resolved', function () {
        expect(Resolution::factory()->autoResolved()->create()->wasAutoResolved())->toBeTrue();
    });

    it('does not treat a human decision as auto-resolved', function () {
        expect(Resolution::factory()->humanReviewed()->create()->wasAutoResolved())->toBeFalse();
    });

    it('turns a human correction into a reusable variant', function () {
        $item = CanonicalItem::factory()->create();
        $variant = CanonicalItemVariant::factory()->fromHumanReview()->create([
            'canonical_item_id' => $item->id,
        ]);

        expect($variant->source)->toBe(CanonicalItemVariant::SOURCE_HUMAN_REVIEW)
            ->and($item->variants()->count())->toBe(1);
    });

    it('counts how often a variant has resolved a submission', function () {
        $variant = CanonicalItemVariant::factory()->create();

        $variant->recordMatch();
        $variant->recordMatch();

        expect($variant->fresh()->times_matched)->toBe(2);
    });

    it('builds embeddable text from names and known variants', function () {
        $item = CanonicalItem::factory()->create(['name_en' => 'Infant formula', 'name_local' => 'حليب اطفال']);
        CanonicalItemVariant::factory()->create(['canonical_item_id' => $item->id, 'text' => 'baby milk']);

        $text = $item->load('variants')->embeddableText();

        expect($text)->toContain('Infant formula')
            ->and($text)->toContain('حليب اطفال')
            ->and($text)->toContain('baby milk');
    });

    it('knows when an embedding predates the current model', function () {
        $current = 'intfloat/multilingual-e5-base';

        expect(CanonicalItem::factory()->make()->needsEmbedding($current))->toBeTrue()
            ->and(CanonicalItem::factory()->staleEmbedding()->make()->needsEmbedding($current))->toBeTrue()
            ->and(CanonicalItem::factory()->embedded()->make()->needsEmbedding($current))->toBeFalse();
    });
});

describe('ingestion batches', function () {
    it('reports a clean import as fully accepted', function () {
        $batch = IngestionBatch::factory()->make();

        expect($batch->acceptanceRate())->toBe(1.0)
            ->and($batch->hasErrors())->toBeFalse();
    });

    it('imports good rows and reports the bad ones individually', function () {
        // Partial success is the normal outcome for a real partner file; the
        // alternative is rejecting 900 good rows because 12 were malformed.
        $batch = IngestionBatch::factory()->partiallyRejected(12)->make();

        expect($batch->accepted_count)->toBe(88)
            ->and($batch->hasErrors())->toBeTrue()
            ->and($batch->errorRows())->toHaveCount(12)
            ->and($batch->errorRows()[0]['message'])->toBe('Price is not a number');
    });

    it('reports no acceptance rate for an empty file', function () {
        expect(IngestionBatch::factory()->make(['row_count' => 0])->acceptanceRate())->toBeNull();
    });

    it('returns no error rows when a batch failed outright', function () {
        expect(IngestionBatch::factory()->failed()->make()->errorRows())->toBe([]);
    });

    it('refuses to record the same file against a source twice', function () {
        $batch = IngestionBatch::factory()->create();

        expect(fn () => IngestionBatch::factory()->create([
            'source_id' => $batch->source_id,
            'checksum' => $batch->checksum,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('scraper resumption', function () {
    it('has no cursor before its first run', function () {
        expect(Source::factory()->scraper()->create()->resumeCursor())->toBeNull();
    });

    it('persists a cursor so an interrupted run resumes', function () {
        $source = Source::factory()->scraper()->create();

        $source->setResumeCursor('page-7');

        expect($source->fresh()->resumeCursor())->toBe('page-7');
    });

    it('clears the cursor at the end of a full run', function () {
        $source = Source::factory()->scraper('page-7')->create();

        $source->setResumeCursor(null);

        expect($source->fresh()->resumeCursor())->toBeNull();
    });
});
