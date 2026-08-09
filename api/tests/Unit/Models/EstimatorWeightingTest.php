<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Unit;

/*
|--------------------------------------------------------------------------
| Estimator weighting and unit normalisation
|--------------------------------------------------------------------------
|
| These are the numbers the published index is built from, so they are tested
| against hand-computed expected values rather than "it returned something".
|
*/

describe('unit normalisation', function () {
    it('converts a sub-unit price to a price per base unit', function () {
        $gram = Unit::factory()->gram()->make();

        // 12.50 for 500 g is 25.00 per kg.
        expect($gram->pricePerBaseUnit(12.50, 500))->toBe(25.0);
    });

    it('leaves a base-unit price unchanged', function () {
        $kg = Unit::factory()->make();

        expect($kg->pricePerBaseUnit(25.0, 1))->toBe(25.0);
    });

    it('normalises a multi-unit purchase', function () {
        $kg = Unit::factory()->make();

        // 50.00 for a 2 kg bag is 25.00 per kg.
        expect($kg->pricePerBaseUnit(50.0, 2))->toBe(25.0);
    });

    it('handles volume units independently of mass', function () {
        $ml = Unit::factory()->millilitre()->make();

        // 3.00 for a 750 ml bottle is 4.00 per litre.
        expect($ml->pricePerBaseUnit(3.0, 750))->toBe(4.0);
    });

    it('refuses a zero quantity rather than dividing by zero', function () {
        $kg = Unit::factory()->make();

        expect(fn () => $kg->pricePerBaseUnit(10.0, 0))
            ->toThrow(InvalidArgumentException::class, 'Quantity must be positive');
    });

    it('refuses a negative quantity', function () {
        $kg = Unit::factory()->make();

        expect(fn () => $kg->pricePerBaseUnit(10.0, -1))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a unit with a non-positive conversion factor', function () {
        // A misconfigured country file must fail loudly, not silently produce
        // an infinite price that would poison the index.
        $broken = Unit::factory()->make(['code' => 'bad', 'factor_to_base' => 0.0]);

        expect(fn () => $broken->pricePerBaseUnit(10.0, 1))
            ->toThrow(InvalidArgumentException::class, 'non-positive conversion factor');
    });
});

describe('observation weighting', function () {
    it('gives a same-day observation its full reputation weight', function () {
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-03-10',
            'reputation_at_time' => 0.8,
        ]);

        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 3.0))
            ->toBe(0.8);
    });

    it('halves the weight after exactly one half-life', function () {
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-03-07',
            'reputation_at_time' => 0.8,
        ]);

        // Three days old with a three-day half-life: 0.8 * 0.5.
        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 3.0))
            ->toBe(0.4);
    });

    it('quarters the weight after two half-lives', function () {
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-03-04',
            'reputation_at_time' => 0.8,
        ]);

        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 3.0))
            ->toBe(0.2);
    });

    it('applies no decay when the half-life is disabled', function () {
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-01-01',
            'reputation_at_time' => 0.6,
        ]);

        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 0.0))
            ->toBe(0.6);
    });

    it('floors a poor reputation so a reporter can climb back', function () {
        // Without the floor an unreliable reporter is weighted to nothing,
        // deviates further from an estimate they no longer influence, and can
        // never recover. This asserts the escape hatch exists.
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-03-10',
            'reputation_at_time' => 0.02,
        ]);

        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 3.0))
            ->toBe(Reporter::WEIGHT_FLOOR);
    });

    it('never returns a negative weight for a future-dated observation', function () {
        $obs = PriceObservation::factory()->make([
            'observed_on' => '2026-03-20',
            'reputation_at_time' => 0.5,
        ]);

        expect($obs->estimatorWeight(new DateTimeImmutable('2026-03-10'), 3.0))
            ->toBeGreaterThan(0.0);
    });
});

describe('reporter reputation', function () {
    it('starts a new reporter at one half with an uninformative prior', function () {
        $reporter = Reporter::factory()->coldStart()->make();

        expect($reporter->posteriorMean())->toBe(0.5);
    });

    it('does not treat one accepted submission as a perfect record', function () {
        // The reason for a Beta posterior rather than accepted/total: a raw
        // ratio would report 1.0 here, ranking a single lucky submission above
        // a reporter with a hundred good ones.
        $reporter = Reporter::factory()->coldStart()->make();

        $reporter->reputation_alpha += 1;

        expect($reporter->posteriorMean())->toBeLessThan(0.7)
            ->and($reporter->posteriorMean())->toBeGreaterThan(0.5);
    });

    it('converges upward with a long accepted record', function () {
        $reporter = Reporter::factory()->trusted()->make();

        expect($reporter->posteriorMean())->toBeGreaterThan(0.95);
    });

    it('converges downward with a long rejected record', function () {
        $reporter = Reporter::factory()->unreliable()->make();

        expect($reporter->posteriorMean())->toBeLessThan(0.15);
    });

    it('floors the estimator weight even for the worst reporter', function () {
        $reporter = Reporter::factory()->unreliable()->make();

        expect($reporter->weight())->toBe(Reporter::WEIGHT_FLOOR);
    });

    it('uses the real reputation as the weight when it exceeds the floor', function () {
        $reporter = Reporter::factory()->trusted()->make();

        expect($reporter->weight())->toBe($reporter->reputation);
    });
});
