<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Text\TextNormalizer;

/*
|--------------------------------------------------------------------------
| The normaliser, against every real string in the repository
|--------------------------------------------------------------------------
|
| This transform exists twice: here, for seeding and for the Postgres trigram
| queries, and in Python for the matcher. The Python module's own docstring
| calls that duplication dangerous, and it is right — text normalised one way at
| index time and another at query time simply fails to match, and nothing
| errors. A drift between the two is silent.
|
| Twenty-two hand-written fixtures guarded that. This runs the same transform
| over 3,887 strings people actually wrote — every catalogue variant, corpus
| wording, distractor and evaluation row the repository contains — against the
| output both implementations were verified to agree on before it was recorded.
|
| It proves consistency, not correctness. Agreeing with Python does not make
| either side right; what it removes is the failure mode where they quietly stop
| agreeing.
|
| Measured against the fixtures it was meant to strengthen, by disabling each of
| the eight character folds in turn: the 22 fixtures caught eight of eight, this
| caught seven. It misses alef wasla, because no real string in the corpus has
| one. The fixtures cover every fold on purpose; real text only contains what
| people write. This is a complement, not a replacement, and dropping either
| leaves a gap.
|
*/

/** @return list<array{input: string, normalized: string}> */
function normalisationCorpus(): array
{
    $path = base_path('../contracts/text-normalisation-corpus.json');

    /** @var array{cases: list<array{input: string, normalized: string}>} $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $decoded['cases'];
}

it('has a substantial corpus to check', function (): void {
    // Guards the guard: a truncated contract would pass everything below while
    // testing almost nothing.
    expect(count(normalisationCorpus()))->toBeGreaterThanOrEqual(3800);
});

it('normalises every real string exactly as Python does', function (): void {
    $normalizer = new TextNormalizer;
    $mismatches = [];

    foreach (normalisationCorpus() as $case) {
        $actual = $normalizer->normalize($case['input']);

        if ($actual !== $case['normalized']) {
            $mismatches[] = sprintf(
                '%s -> php %s, contract %s',
                $case['input'],
                $actual,
                $case['normalized'],
            );
        }
    }

    expect($mismatches)->toBe([], sprintf(
        '%d of %d real strings normalise differently here than in the Python half. '
        .'Index-time and query-time text would stop matching, silently. First: %s',
        count($mismatches),
        count(normalisationCorpus()),
        implode(' | ', array_slice($mismatches, 0, 3)),
    ));
});

it('leaves an already normalised string alone', function (): void {
    // Idempotence over real text rather than over a handful of examples. The
    // matcher normalises at index time and again at query time; were that not
    // idempotent, a variant would stop matching itself.
    $normalizer = new TextNormalizer;
    $changed = [];

    foreach (normalisationCorpus() as $case) {
        if ($normalizer->normalize($case['normalized']) !== $case['normalized']) {
            $changed[] = $case['normalized'];
        }
    }

    expect($changed)->toBe([], count($changed).' strings change on a second pass.');
});
