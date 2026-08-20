<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| A variant filed under the wrong item is worse than a missing one
|--------------------------------------------------------------------------
|
| A missing variant sends a report to the review queue. A *misfiled* one sends
| it confidently to the wrong product, and the wrong product has a different
| price. That is a silent distortion of a published figure, which is the failure
| this project exists to avoid.
|
| It happened. A script that appends to a country file located each item's
| `variants:` line and inserted after it — correct for the block style almost
| every item uses, and wrong for the two written inline as `variants: [a, b]`.
| For those the search ran past the item entirely and landed in the *next* one.
| Nine wordings for eggs, دحي among them, were filed under canned tuna, and four
| for sanitary pads under an 11kg cooking gas cylinder. Both files parsed
| cleanly. Both would have matched.
|
| The corpus is what makes this checkable: it already says which item each
| wording belongs to. So any wording the corpus assigns to item X must not
| appear in the catalogue under item Y.
|
*/

/** @return array{catalogue: array<string, list<string>>, corpus: array<string, list<string>>} */
function placementFixture(string $code): array
{
    /** @var array<string, mixed> $config */
    $config = Yaml::parseFile(base_path("../countries/{$code}.yaml"));

    $catalogue = [];
    foreach ($config['canonical_items'] as $item) {
        $catalogue[(string) $item['code']] = array_map(
            static fn ($v): string => normaliseWording((string) $v),
            $item['variants'] ?? [],
        );
    }

    $corpusPath = base_path("../countries/corpus/{$code}.json");
    $corpus = [];

    if (is_file($corpusPath)) {
        /** @var array{items?: array<string, list<string>>} $decoded */
        $decoded = json_decode((string) file_get_contents($corpusPath), true, 512, JSON_THROW_ON_ERROR);

        foreach ($decoded['items'] ?? [] as $itemCode => $wordings) {
            $corpus[(string) $itemCode] = array_map(
                static fn ($v): string => normaliseWording((string) $v),
                $wordings,
            );
        }
    }

    return ['catalogue' => $catalogue, 'corpus' => $corpus];
}

function normaliseWording(string $value): string
{
    $value = trim($value);
    $value = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $value);
    $value = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $value);

    return mb_strtolower((string) preg_replace('/\s+/u', ' ', $value));
}

/** @return list<string> */
function countryCodes(): array
{
    $out = [];

    foreach (glob(base_path('../countries/*.yaml')) ?: [] as $path) {
        $out[] = basename($path, '.yaml');
    }

    return $out;
}

it('has country files to check', function (): void {
    expect(countryCodes())->not->toBeEmpty();
});

it('never files a wording under an item the corpus assigns to a different one', function (): void {
    foreach (countryCodes() as $code) {
        ['catalogue' => $catalogue, 'corpus' => $corpus] = placementFixture($code);

        // Which item does the corpus say each wording belongs to? A wording the
        // corpus lists against several items is ambiguous rather than wrong, so
        // it is skipped instead of being made a failure.
        $owners = [];
        foreach ($corpus as $itemCode => $wordings) {
            foreach ($wordings as $wording) {
                $owners[$wording][$itemCode] = true;
            }
        }

        foreach ($catalogue as $itemCode => $wordings) {
            foreach ($wordings as $wording) {
                $claimed = array_keys($owners[$wording] ?? []);

                if (count($claimed) !== 1 || $claimed[0] === $itemCode) {
                    continue;
                }

                expect($claimed[0])->toBe(
                    $itemCode,
                    "{$code}.yaml: '{$wording}' is a catalogue variant of {$itemCode}, but the "
                    ."corpus lists it as a wording for {$claimed[0]}. One of the two is wrong, and "
                    .'a misfiled variant resolves a report confidently to the wrong product.',
                );
            }
        }
    }
});

it('never gives two items the same variant', function (): void {
    // The other half of the same failure: if one string is a variant of two
    // items, the matcher's answer depends on index order rather than on meaning.
    foreach (countryCodes() as $code) {
        ['catalogue' => $catalogue] = placementFixture($code);

        $seen = [];
        foreach ($catalogue as $itemCode => $wordings) {
            foreach ($wordings as $wording) {
                if ($wording === '') {
                    continue;
                }

                expect($seen[$wording] ?? $itemCode)->toBe(
                    $itemCode,
                    "{$code}.yaml: '{$wording}' is a variant of both {$itemCode} and "
                    .($seen[$wording] ?? '?').'. A string cannot mean two products.',
                );

                $seen[$wording] = $itemCode;
            }
        }
    }
});
