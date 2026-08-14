<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Synthetic\RawTextGenerator;
use App\Support\Synthetic\ReporterCorpus;
use App\Support\Text\TextNormalizer;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
|--------------------------------------------------------------------------
| The reporter corpus
|--------------------------------------------------------------------------
|
| `RawTextGenerator` mutates catalogue names using the same transformations the
| matcher's normaliser undoes, because both were written from one list. A score
| measured against that text partly measures whether the normaliser was
| implemented correctly. The corpus exists to break that circularity, and these
| tests hold the two properties that make it worth having: it is loaded safely
| from a file a human may edit, and it genuinely reaches the generated text.
|
*/

function corpusFixture(array $doc, string $code = 'xx'): string
{
    $dir = sys_get_temp_dir().'/qeema-corpus-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    file_put_contents($dir.'/'.$code.'.json', json_encode($doc));

    return $dir;
}

it('is empty for a country with no corpus file', function (): void {
    // The shipped demo depends on this: absence must be silent and must change
    // nothing about what the generator produces.
    $corpus = ReporterCorpus::forCountry('zz', corpusFixture([], 'xx'));

    expect($corpus->isEmpty())->toBeTrue()
        ->and($corpus->phrasingsFor('anything'))->toBe([]);
});

it('survives a malformed file rather than taking the generator down with it', function (): void {
    $dir = sys_get_temp_dir().'/qeema-corpus-'.bin2hex(random_bytes(4));
    mkdir($dir, 0o777, true);
    file_put_contents($dir.'/xx.json', 'this is not json');

    expect(ReporterCorpus::forCountry('xx', $dir)->isEmpty())->toBeTrue();
});

it('discards entries that are not lists of strings', function (): void {
    // The file is data somebody may hand-edit. A half-broken entry should drop
    // out rather than reach the generator as a type error mid-run.
    $dir = corpusFixture([
        'items' => [
            'good' => ['one', 'two'],
            'mixed' => ['keep', 42, null, 'also'],
            'empty' => [],
            'wrong' => 'not a list',
        ],
    ]);

    $corpus = ReporterCorpus::forCountry('xx', $dir);

    expect($corpus->phrasingsFor('good'))->toBe(['one', 'two'])
        ->and($corpus->phrasingsFor('mixed'))->toBe(['keep', 'also'])
        ->and($corpus->phrasingsFor('empty'))->toBe([])
        ->and($corpus->phrasingsFor('wrong'))->toBe([]);
});

it('puts corpus wordings into the generated text', function (): void {
    $corpus = new ReporterCorpus(phrasings: ['rice' => ['ZZQQ-marker-one', 'ZZQQ-marker-two']]);
    $generator = new RawTextGenerator(new Randomizer(new Mt19937(11)), $corpus);

    $seen = 0;

    for ($i = 0; $i < 200; $i++) {
        if (str_contains($generator->generate('Rice', ['arroz'], 'rice'), 'ZZQQ-marker')) {
            $seen++;
        }
    }

    // Most, not all: a stream that never produced the catalogue name would be
    // its own kind of unrealistic.
    expect($seen)->toBeGreaterThan(100)->toBeLessThan(200);
});

it('ignores the corpus for an item it has no wordings for', function (): void {
    $corpus = new ReporterCorpus(phrasings: ['rice' => ['ZZQQ-marker']]);
    $generator = new RawTextGenerator(new Randomizer(new Mt19937(11)), $corpus);

    for ($i = 0; $i < 40; $i++) {
        expect($generator->generate('Flour', ['harina'], 'flour'))->not->toContain('ZZQQ-marker');
    }
});

it('behaves exactly as before when no corpus is supplied', function (): void {
    // The property that keeps `qeema:bootstrap` producing what it always has.
    $withNone = new RawTextGenerator(new Randomizer(new Mt19937(99)));
    $withEmpty = new RawTextGenerator(new Randomizer(new Mt19937(99)), ReporterCorpus::empty());

    for ($i = 0; $i < 50; $i++) {
        expect($withNone->generate('Rice', ['arroz'], 'rice'))
            ->toBe($withEmpty->generate('Rice', ['arroz'], 'rice'));
    }
});

it('ships corpora whose item codes all exist in their country file', function (): void {
    // A phrasing filed under a code that is not in the catalogue can never be
    // reached, and would silently shrink the corpus rather than fail.
    $normalizer = new TextNormalizer;

    foreach (glob(base_path('../countries/corpus/*.json')) ?: [] as $path) {
        // The country file is lowercase on disk. Deriving an uppercase code and
        // reading it back passed on a case-insensitive macOS filesystem and
        // failed on Linux, which is where CI runs.
        $slug = pathinfo($path, PATHINFO_FILENAME);
        $code = strtoupper($slug);
        $yamlPath = base_path("../countries/{$slug}.yaml");

        expect(is_file($yamlPath))->toBeTrue("no country file for corpus {$slug}");

        $yaml = (string) file_get_contents($yamlPath);

        $corpus = ReporterCorpus::forCountry($code, dirname($path));

        expect($corpus->isEmpty())->toBeFalse();

        /** @var array<string, list<string>> $items */
        $items = json_decode((string) file_get_contents($path), true)['items'];

        $unknown = [];
        $vanishing = [];

        foreach (array_keys($items) as $itemCode) {
            if (! str_contains($yaml, "code: {$itemCode}")) {
                $unknown[] = $itemCode;
            }

            foreach ($corpus->phrasingsFor($itemCode) as $phrasing) {
                // A wording that normalises to nothing can never match anything,
                // so it is dead weight in the corpus rather than a hard case.
                if (trim($normalizer->normalize($phrasing)) === '') {
                    $vanishing[] = "{$itemCode}: {$phrasing}";
                }
            }
        }

        expect($unknown)->toBe([], "{$code} corpus files items not in the catalogue");
        expect($vanishing)->toBe([], "{$code} corpus has wordings that normalise to nothing");
    }
});

it('samples the wordings that dominate real traffic far more often', function (): void {
    // Uniform sampling tests the long tail harder than reality does and the head
    // far less, so a matcher can look good on a corpus while failing on the
    // handful of phrasings that would be most of the actual traffic.
    $corpus = new ReporterCorpus(
        phrasings: ['rice' => ['DOMINANT', 'second', 'a', 'b', 'c', 'd', 'e', 'f']],
        heads: ['rice' => ['DOMINANT', 'second']],
    );

    $generator = new RawTextGenerator(new Randomizer(new Mt19937(5)), $corpus);

    $dominant = 0;
    $tail = 0;

    for ($i = 0; $i < 600; $i++) {
        $text = $generator->generate('Rice', [], 'rice');

        if (str_contains($text, 'DOMINANT')) {
            $dominant++;
        } elseif (preg_match('/\b[a-f]\b/', $text)) {
            $tail++;
        }
    }

    // Weight 40 against six tail entries at 1 each: the head must clearly win.
    expect($dominant)->toBeGreaterThan($tail);
});

it('treats every wording as equally likely when no head is declared', function (): void {
    $corpus = new ReporterCorpus(phrasings: ['rice' => ['aaa', 'bbb']]);

    expect($corpus->weightedPhrasingsFor('rice'))->toBe([['aaa', 1], ['bbb', 1]]);
});

it('never files a distractor as a wording of a real item', function (): void {
    // A distractor that is also a catalogue wording is a mislabelled row: it
    // would be scored as a false positive when matching it was correct.
    foreach (glob(base_path('../countries/corpus/*.json')) ?: [] as $path) {
        /** @var array<string, mixed> $doc */
        $doc = json_decode((string) file_get_contents($path), true);

        $wordings = [];

        foreach ($doc['items'] as $phrasings) {
            foreach ($phrasings as $phrasing) {
                $wordings[trim((string) $phrasing)] = true;
            }
        }

        $collisions = array_values(array_filter(
            $doc['distractors'] ?? [],
            fn (string $text): bool => isset($wordings[trim($text)]),
        ));

        expect($collisions)->toBe([], basename($path).' has distractors that are also catalogue wordings');
    }
});

it('declares heads that are real wordings of the item they belong to', function (): void {
    // Heads are matched back by string, so a head that does not appear in the
    // item's own list is silently ignored and the weighting quietly does nothing.
    foreach (glob(base_path('../countries/corpus/*.json')) ?: [] as $path) {
        /** @var array<string, mixed> $doc */
        $doc = json_decode((string) file_get_contents($path), true);

        $orphans = [];

        foreach ($doc['heads'] ?? [] as $code => $tops) {
            $pool = array_map('trim', $doc['items'][$code] ?? []);

            foreach ($tops as $top) {
                if (! in_array(trim((string) $top), $pool, true)) {
                    $orphans[] = "{$code}: {$top}";
                }
            }
        }

        expect($orphans)->toBe([], basename($path).' declares heads that are not wordings of their item');
    }
});
