<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Text\TextNormalizer;

/*
|--------------------------------------------------------------------------
| Text normalisation
|--------------------------------------------------------------------------
|
| Driven by contracts/text-normalisation.json, the same file the Python
| normaliser is tested against. If the two implementations ever diverge, one of
| these suites fails — which is the entire point of sharing the fixtures.
|
*/

/**
 * @return list<array{name: string, input: string, expected: string}>
 */
function normalisationCases(): array
{
    $path = base_path('../contracts/text-normalisation.json');

    expect(file_exists($path))->toBeTrue("Shared contract file missing at {$path}");

    /** @var array{cases: list<array{name: string, input: string, expected: string}>} $contract */
    $contract = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $contract['cases'];
}

it('satisfies every shared normalisation contract case', function () {
    $normalizer = new TextNormalizer;
    $failures = [];

    foreach (normalisationCases() as $case) {
        $actual = $normalizer->normalize($case['input']);

        if ($actual !== $case['expected']) {
            $failures[] = sprintf(
                "%s\n    input:    %s\n    expected: %s\n    actual:   %s",
                $case['name'],
                json_encode($case['input'], JSON_UNESCAPED_UNICODE),
                json_encode($case['expected'], JSON_UNESCAPED_UNICODE),
                json_encode($actual, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    expect($failures)->toBe([], "Normalisation contract violations:\n".implode("\n", $failures));
});

it('covers a meaningful number of cases', function () {
    // Guards against the contract file being emptied and the suite above
    // passing vacuously.
    expect(normalisationCases())->toHaveCount(22);
});

it('is idempotent across every contract case', function () {
    $normalizer = new TextNormalizer;

    foreach (normalisationCases() as $case) {
        $once = $normalizer->normalize($case['input']);
        $twice = $normalizer->normalize($once);

        expect($twice)->toBe($once, "Not idempotent for: {$case['name']}");
    }
});

it('makes the two spellings of infant formula identical', function () {
    // The measured motivation: unnormalised, these score only 0.571 trigram
    // similarity despite being the same product spelled two ordinary ways.
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('حليب أطفال'))
        ->toBe($normalizer->normalize('حليب اطفال'));
});

it('makes Arabic-Indic and ASCII digits match', function () {
    $normalizer = new TextNormalizer;

    expect($normalizer->normalize('حليب ٤٠٠ غرام'))
        ->toBe($normalizer->normalize('حليب 400 غرام'));
});

it('returns an empty string for null', function () {
    expect((new TextNormalizer)->normalize(null))->toBe('');
});

describe('tokenisation', function () {
    it('splits a normalised string into tokens', function () {
        expect((new TextNormalizer)->tokenize('حليب  أطفال ٤٠٠'))
            ->toBe(['حليب', 'اطفال', '400']);
    });

    it('returns no tokens for empty input', function () {
        expect((new TextNormalizer)->tokenize(''))->toBe([])
            ->and((new TextNormalizer)->tokenize(null))->toBe([])
            ->and((new TextNormalizer)->tokenize('   '))->toBe([]);
    });
});
