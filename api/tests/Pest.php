<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests hit a real PostgreSQL database (see phpunit.xml): pgvector and
| pg_trgm have no SQLite equivalent, and the matcher is meaningless without
| them. RefreshDatabase wraps each test in a transaction, which is fast enough
| that a real database costs little.
|
*/

// Both suites get RefreshDatabase. Even "unit" tests of domain logic build
// their subjects through Eloquent factories, and a factory that touches the
// database without a rolled-back transaction leaks rows into later tests —
// which shows up as unrelated unique-constraint failures that are tedious to
// trace back to their real cause.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert a published price-bearing payload never hides whether it was imputed.
 *
 * Silently mixing imputed values with observed ones is the single most
 * damaging thing this platform could do to its own credibility, so the
 * invariant gets a first-class expectation.
 */
expect()->extend('toDeclareImputationStatus', function () {
    expect($this->value)->toHaveKey('is_imputed');
    expect($this->value['is_imputed'])->toBeBool();

    return $this;
});
